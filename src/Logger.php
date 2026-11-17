<?php

namespace App;

final class Logger
{
    private $level;

    public function __construct($level = 'info')
    {
        $this->level = $level;
    }

    public function debug($message, array $context = array()) { $this->log('debug', $message, $context); }
    public function info($message, array $context = array()) { $this->log('info', $message, $context); }
    public function warning($message, array $context = array()) { $this->log('warning', $message, $context); }
    public function error($message, array $context = array()) { $this->log('error', $message, $context); }

    private function log($level, $message, array $context)
    {
        $levels = array('debug' => 10, 'info' => 20, 'warning' => 30, 'error' => 40);
        $currentLevel = isset($levels[$this->level]) ? $levels[$this->level] : 20;
        $messageLevel = isset($levels[$level]) ? $levels[$level] : 20;
        if ($messageLevel < $currentLevel) {
            return;
        }
        $record = [
            'time' => (new \DateTime('now'))->format(DATE_ATOM),
            'level' => strtoupper($level),
            'message' => $message,
        ];
        if ($context !== []) {
            $record['context'] = $context;
        }
        $stream = $level === 'error' ? STDERR : STDOUT;
        fwrite($stream, self::encodeJson($record) . PHP_EOL);
    }

    private static function encodeJson($value)
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? '{"level":"ERROR","message":"JSON encoding failed"}' : $json;
    }
}
