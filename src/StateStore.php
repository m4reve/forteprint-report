<?php

namespace App;

final class StateStore
{
    private $path;

    public function __construct($path)
    {
        $this->path = $path;
    }

    public function lastSuccessfulSync()
    {
        if (!is_file($this->path)) {
            return null;
        }
        $raw = file_get_contents($this->path);
        if ($raw === false) {
            throw new \RuntimeException("Не удалось прочитать state-файл {$this->path}");
        }
        $state = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($state)) {
            throw new \RuntimeException("Некорректный JSON в state-файле {$this->path}");
        }
        return isset($state['last_successful_sync']) ? (string) $state['last_successful_sync'] : null;
    }

    public function save($time)
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException("Не удалось создать каталог {$directory}");
        }
        $temporary = $this->path . '.tmp';
        $json = json_encode(['last_successful_sync' => $time], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        if ($json === false) {
            throw new \RuntimeException('Не удалось сериализовать state-файл');
        }
        if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $this->path)) {
            throw new \RuntimeException("Не удалось сохранить state-файл {$this->path}");
        }
    }
}
