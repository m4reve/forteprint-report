<?php

namespace App;

class HttpClient
{
    private $timeoutSeconds;
    private $maxAttempts;

    public function __construct($timeoutSeconds = 30, $maxAttempts = 4)
    {
        $this->timeoutSeconds = (int) $timeoutSeconds;
        $this->maxAttempts = (int) $maxAttempts;
    }

    public function requestJson($method, $url, array $headers = array(), $body = null)
    {
        $lastError = null;
        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new \RuntimeException('Не удалось инициализировать cURL');
            }

            $requestHeaders = array_merge(['Accept: application/json'], $headers);
            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => strtoupper($method),
                CURLOPT_CONNECTTIMEOUT => min(10, $this->timeoutSeconds),
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
                CURLOPT_HTTPHEADER => $requestHeaders,
            ];
            if ($body !== null) {
                $payload = $this->encodeJson($body);
                $options[CURLOPT_POSTFIELDS] = $payload;
                $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
            }
            curl_setopt_array($ch, $options);
            $raw = curl_exec($ch);
            $curlError = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($raw !== false && $status >= 200 && $status < 300) {
                $decoded = $this->decodeJson($raw);
                return is_array($decoded) ? $decoded : [];
            }

            $lastError = $curlError !== '' ? $curlError : "HTTP {$status}: " . mb_substr((string) $raw, 0, 1000);
            if (!in_array($status, [0, 429, 500, 502, 503, 504], true) || $attempt === $this->maxAttempts) {
                break;
            }
            if ($status === 429) {
                // Минутная квота Google не восстановится за доли секунды.
                // Усеченная экспоненциальная пауза с небольшим случайным сдвигом.
                $delayMilliseconds = min(15000 * pow(2, $attempt - 1), 60000) + mt_rand(0, 1000);
                usleep((int) ($delayMilliseconds * 1000));
            } else {
                usleep((int) (250000 * pow(2, $attempt - 1)));
            }
        }

        throw new \RuntimeException("Ошибка HTTP-запроса: {$lastError}");
    }

    public function postForm($url, array $form)
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Не удалось инициализировать cURL');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($form),
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeoutSeconds),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
        ]);
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $status < 200 || $status >= 300) {
            throw new \RuntimeException('Ошибка OAuth HTTP ' . $status . ': ' . ($error !== '' ? $error : mb_substr((string) $raw, 0, 1000)));
        }
        $decoded = $this->decodeJson($raw);
        return is_array($decoded) ? $decoded : [];
    }

    private function encodeJson($value)
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Ошибка кодирования JSON: ' . $this->jsonErrorMessage());
        }
        return $json;
    }

    private function decodeJson($json)
    {
        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Ошибка разбора JSON: ' . $this->jsonErrorMessage());
        }
        return $decoded;
    }

    private function jsonErrorMessage()
    {
        if (function_exists('json_last_error_msg')) {
            return json_last_error_msg();
        }
        return 'код ' . json_last_error();
    }
}
