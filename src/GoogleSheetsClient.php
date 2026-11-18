<?php

namespace App;

class GoogleSheetsClient
{
    /**
     * Общий ограничитель для всех экземпляров клиента в одном PHP-процессе.
     * Это важно для sync-managers.php, который последовательно пишет в несколько таблиц.
     */
    private static $lastWriteRequestAt = 0.0;

    private $accessToken = null;
    private $accessTokenExpiresAt = 0;
    private $credentials;
    private $http;
    private $spreadsheetId;
    private $sheetProperties = array();
    private $sheetPropertiesLoaded = false;
    private $writeRequestIntervalMs;
    private $writeRateFile;

    public function __construct(HttpClient $http, $credentialsPath, $spreadsheetId, $writeRequestIntervalMs = 1200, $writeRateFile = null)
    {
        $this->http = $http;
        $this->spreadsheetId = $spreadsheetId;
        $this->writeRequestIntervalMs = max(0, (int) $writeRequestIntervalMs);
        $writeRateFile = trim((string) $writeRateFile);
        $this->writeRateFile = $writeRateFile === '' ? null : $writeRateFile;
        if (!is_file($credentialsPath)) {
            throw new \RuntimeException("Не найден JSON-ключ Google: {$credentialsPath}");
        }
        $raw = file_get_contents($credentialsPath);
        if ($raw === false) {
            throw new \RuntimeException("Не удалось прочитать JSON-ключ Google: {$credentialsPath}");
        }
        $this->credentials = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($this->credentials)) {
            throw new \RuntimeException('JSON-ключ Google содержит некорректный JSON');
        }
        foreach (['client_email', 'private_key', 'token_uri'] as $key) {
            if (empty($this->credentials[$key])) {
                throw new \RuntimeException("В JSON-ключе Google отсутствует {$key}");
            }
        }
    }

    public function getValues($range)
    {
        $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($this->spreadsheetId)
            . '/values/' . rawurlencode($range) . '?majorDimension=ROWS&valueRenderOption=FORMULA';
        $response = $this->http->requestJson('GET', $url, $this->authHeaders());
        return isset($response['values']) ? $response['values'] : array();
    }

    public function batchUpdate(array $data, $valueInputOption = 'RAW')
    {
        if ($data === []) {
            return 0;
        }
        $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($this->spreadsheetId) . '/values:batchUpdate';
        $headers = $this->authHeaders();
        $this->throttleWriteRequest();
        $response = $this->http->requestJson('POST', $url, $headers, [
            'valueInputOption' => (string) $valueInputOption,
            'data' => $data,
        ]);
        return (int) (isset($response['totalUpdatedCells']) ? $response['totalUpdatedCells'] : 0);
    }

    public function batchClear(array $ranges)
    {
        if ($ranges === array()) {
            return 0;
        }
        $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($this->spreadsheetId) . '/values:batchClear';
        $headers = $this->authHeaders();
        $this->throttleWriteRequest();
        $response = $this->http->requestJson('POST', $url, $headers, array('ranges' => array_values($ranges)));
        return isset($response['clearedRanges']) && is_array($response['clearedRanges'])
            ? count($response['clearedRanges'])
            : count($ranges);
    }

    public function sheetExists($sheetName)
    {
        $this->loadSheetProperties();
        return isset($this->sheetProperties[(string) $sheetName]);
    }

    public function listSheetNames()
    {
        $this->loadSheetProperties();
        return array_keys($this->sheetProperties);
    }

    /** Создает пустой лист, если его еще нет. */
    public function ensureSheet($sheetName, $frozenRowCount = 0)
    {
        $sheetName = (string) $sheetName;
        $frozenRowCount = max(0, (int) $frozenRowCount);
        $this->loadSheetProperties();
        if (isset($this->sheetProperties[$sheetName])) {
            return false;
        }

        $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($this->spreadsheetId) . ':batchUpdate';
        $headers = $this->authHeaders();
        $this->throttleWriteRequest();
        $response = $this->http->requestJson('POST', $url, $headers, array(
            'requests' => array(
                array(
                    'addSheet' => array(
                        'properties' => array(
                            'title' => $sheetName,
                            'gridProperties' => array(
                                'rowCount' => 1000,
                                'columnCount' => 26,
                                'frozenRowCount' => $frozenRowCount,
                            ),
                        ),
                    ),
                ),
            ),
        ));
        $properties = isset($response['replies'][0]['addSheet']['properties'])
            ? $response['replies'][0]['addSheet']['properties']
            : null;
        if (!is_array($properties) || !isset($properties['sheetId'])) {
            // Следующий запрос метаданных даст точную ошибку, если лист не был создан.
            $this->sheetPropertiesLoaded = false;
            $this->sheetProperties = array();
            $this->loadSheetProperties();
            if (!isset($this->sheetProperties[$sheetName])) {
                throw new \RuntimeException("Google Sheets не подтвердил создание листа «{$sheetName}»");
            }
        } else {
            if (!isset($properties['gridProperties']['rowCount'])) {
                $properties['gridProperties']['rowCount'] = 1000;
            }
            if (!isset($properties['gridProperties']['columnCount'])) {
                $properties['gridProperties']['columnCount'] = 26;
            }
            if (!isset($properties['gridProperties']['frozenRowCount'])) {
                $properties['gridProperties']['frozenRowCount'] = $frozenRowCount;
            }
            $this->sheetProperties[$sheetName] = $properties;
        }
        return true;
    }

    /** Закрепляет заданное количество верхних строк, если настройка еще отличается. */
    public function ensureFrozenRows($sheetName, $frozenRowCount)
    {
        $frozenRowCount = max(0, (int) $frozenRowCount);
        $properties = $this->sheetProperties($sheetName);
        $currentCount = isset($properties['gridProperties']['frozenRowCount'])
            ? (int) $properties['gridProperties']['frozenRowCount']
            : 0;
        if ($currentCount === $frozenRowCount) {
            return false;
        }

        $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($this->spreadsheetId) . ':batchUpdate';
        $headers = $this->authHeaders();
        $this->throttleWriteRequest();
        $this->http->requestJson('POST', $url, $headers, array(
            'requests' => array(
                array(
                    'updateSheetProperties' => array(
                        'properties' => array(
                            'sheetId' => (int) $properties['sheetId'],
                            'gridProperties' => array('frozenRowCount' => $frozenRowCount),
                        ),
                        'fields' => 'gridProperties.frozenRowCount',
                    ),
                ),
            ),
        ));
        $this->sheetProperties[$sheetName]['gridProperties']['frozenRowCount'] = $frozenRowCount;
        return true;
    }

    /**
     * Расширяет лист до требуемого количества строк.
     * Возвращает количество добавленных строк.
     */
    public function ensureRowCapacity($sheetName, $requiredRows)
    {
        $requiredRows = (int) $requiredRows;
        if ($requiredRows < 1) {
            return 0;
        }

        $properties = $this->sheetProperties($sheetName);
        $currentRows = isset($properties['gridProperties']['rowCount'])
            ? (int) $properties['gridProperties']['rowCount']
            : 0;
        if ($requiredRows <= $currentRows) {
            return 0;
        }

        // Добавляем минимум 1000 строк, чтобы не расширять лист на каждом запуске.
        $rowsToAdd = max($requiredRows - $currentRows, 1000);
        $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($this->spreadsheetId) . ':batchUpdate';
        $headers = $this->authHeaders();
        $this->throttleWriteRequest();
        $this->http->requestJson('POST', $url, $headers, array(
            'requests' => array(
                array(
                    'appendDimension' => array(
                        'sheetId' => (int) $properties['sheetId'],
                        'dimension' => 'ROWS',
                        'length' => $rowsToAdd,
                    ),
                ),
            ),
        ));
        $this->sheetProperties[$sheetName]['gridProperties']['rowCount'] = $currentRows + $rowsToAdd;
        return $rowsToAdd;
    }

    /** Скрывает или показывает один столбец листа. */
    public function setColumnHidden($sheetName, $columnNumber, $hidden)
    {
        $columnNumber = (int) $columnNumber;
        if ($columnNumber < 1) {
            throw new \InvalidArgumentException('Номер столбца должен быть положительным');
        }
        $properties = $this->sheetProperties($sheetName);
        $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($this->spreadsheetId) . ':batchUpdate';
        $headers = $this->authHeaders();
        $this->throttleWriteRequest();
        $this->http->requestJson('POST', $url, $headers, array(
            'requests' => array(
                array(
                    'updateDimensionProperties' => array(
                        'range' => array(
                            'sheetId' => (int) $properties['sheetId'],
                            'dimension' => 'COLUMNS',
                            'startIndex' => $columnNumber - 1,
                            'endIndex' => $columnNumber,
                        ),
                        'properties' => array('hiddenByUser' => (bool) $hidden),
                        'fields' => 'hiddenByUser',
                    ),
                ),
            ),
        ));
    }

    /** Закрашивает указанные ячейки светло-желтым цветом. */
    public function highlightCells(array $cells, $sheetName)
    {
        if ($cells === array()) {
            return 0;
        }
        $properties = $this->sheetProperties($sheetName);
        $requests = array();
        $seen = array();
        foreach ($cells as $cell) {
            $row = isset($cell['row']) ? (int) $cell['row'] : 0;
            $column = isset($cell['column']) ? (int) $cell['column'] : 0;
            if ($row < 1 || $column < 1) {
                continue;
            }
            $key = $row . ':' . $column;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $requests[] = array(
                'repeatCell' => array(
                    'range' => array(
                        'sheetId' => (int) $properties['sheetId'],
                        'startRowIndex' => $row - 1,
                        'endRowIndex' => $row,
                        'startColumnIndex' => $column - 1,
                        'endColumnIndex' => $column,
                    ),
                    'cell' => array(
                        'userEnteredFormat' => array(
                            'backgroundColor' => array('red' => 1, 'green' => 0.949, 'blue' => 0.8),
                        ),
                    ),
                    'fields' => 'userEnteredFormat.backgroundColor',
                ),
            );
        }
        if ($requests === array()) {
            return 0;
        }

        $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($this->spreadsheetId) . ':batchUpdate';
        $headers = $this->authHeaders();
        $this->throttleWriteRequest();
        $this->http->requestJson('POST', $url, $headers, array('requests' => $requests));
        return count($requests);
    }

    private function sheetProperties($sheetName)
    {
        $sheetName = (string) $sheetName;
        $this->loadSheetProperties();
        if (isset($this->sheetProperties[$sheetName])) {
            return $this->sheetProperties[$sheetName];
        }

        throw new \RuntimeException("В Google Sheets не найден лист «{$sheetName}»");
    }

    private function loadSheetProperties()
    {
        if ($this->sheetPropertiesLoaded) {
            return;
        }
        $fields = 'sheets(properties(sheetId,title,gridProperties(rowCount,columnCount,frozenRowCount)))';
        $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($this->spreadsheetId)
            . '?fields=' . rawurlencode($fields);
        $response = $this->http->requestJson('GET', $url, $this->authHeaders());
        $this->sheetProperties = array();
        $sheets = isset($response['sheets']) ? $response['sheets'] : array();
        foreach ($sheets as $sheet) {
            $properties = isset($sheet['properties']) ? $sheet['properties'] : array();
            if (isset($properties['title']) && isset($properties['sheetId'])) {
                $this->sheetProperties[(string) $properties['title']] = $properties;
            }
        }
        $this->sheetPropertiesLoaded = true;
    }

    private function authHeaders()
    {
        return ['Authorization: Bearer ' . $this->accessToken()];
    }

    private function accessToken()
    {
        if ($this->accessToken !== null && time() < $this->accessTokenExpiresAt - 60) {
            return $this->accessToken;
        }
        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss' => $this->credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/spreadsheets',
            'aud' => $this->credentials['token_uri'],
            'iat' => $now,
            'exp' => $now + 3600,
        ];
        $headerJson = json_encode($header);
        $claimsJson = json_encode($claims);
        if ($headerJson === false || $claimsJson === false) {
            throw new \RuntimeException('Не удалось сформировать JWT для Google');
        }
        $unsigned = $this->base64Url($headerJson) . '.' . $this->base64Url($claimsJson);
        $signature = '';
        if (!openssl_sign($unsigned, $signature, $this->credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Не удалось подписать JWT для Google');
        }
        $jwt = $unsigned . '.' . $this->base64Url($signature);
        $token = $this->http->postForm($this->credentials['token_uri'], [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);
        if (empty($token['access_token'])) {
            throw new \RuntimeException('Google не вернул access_token');
        }
        $this->accessToken = (string) $token['access_token'];
        $this->accessTokenExpiresAt = $now + (int) (isset($token['expires_in']) ? $token['expires_in'] : 3600);
        return $this->accessToken;
    }

    /**
     * Google разрешает не более 60 записей в минуту на пользователя проекта.
     * Интервал 1200 мс оставляет запас: не более 50 запросов в минуту.
     */
    private function throttleWriteRequest()
    {
        if ($this->writeRequestIntervalMs <= 0) {
            return;
        }

        if ($this->writeRateFile !== null) {
            $directory = dirname($this->writeRateFile);
            if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
                throw new \RuntimeException('Не удалось создать каталог ограничителя Google Sheets: ' . $directory);
            }
            $handle = fopen($this->writeRateFile, 'c+');
            if ($handle === false || !flock($handle, LOCK_EX)) {
                if (is_resource($handle)) {
                    fclose($handle);
                }
                throw new \RuntimeException('Не удалось заблокировать файл ограничителя Google Sheets');
            }

            rewind($handle);
            $lastRequestAt = (float) trim((string) stream_get_contents($handle));
            $this->waitForWriteSlot($lastRequestAt);
            $requestAt = microtime(true);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, sprintf('%.6F', $requestAt));
            fflush($handle);
            self::$lastWriteRequestAt = $requestAt;
            flock($handle, LOCK_UN);
            fclose($handle);
            return;
        }

        $this->waitForWriteSlot(self::$lastWriteRequestAt);
        self::$lastWriteRequestAt = microtime(true);
    }

    private function waitForWriteSlot($lastRequestAt)
    {
        $now = microtime(true);
        if ($lastRequestAt > 0) {
            $elapsedMs = ($now - $lastRequestAt) * 1000;
            $waitMs = $this->writeRequestIntervalMs - $elapsedMs;
            if ($waitMs > 0) {
                usleep((int) ceil($waitMs * 1000));
            }
        }
    }

    private function base64Url($value)
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
