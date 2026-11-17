<?php

namespace App;

final class Env
{
    public static function load($path)
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Не найден файл настроек: {$path}");
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new \RuntimeException("Не удалось прочитать файл настроек: {$path}");
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || substr($line, 0, 1) === '#') {
                continue;
            }
            list($name, $value) = array_pad(explode('=', $line, 2), 2, '');
            $name = trim($name);
            $value = trim($value);
            if ($name === '') {
                continue;
            }
            $lastCharacter = strlen($value) > 0 ? substr($value, -1) : '';
            if (strlen($value) >= 2 && (($value[0] === '"' && $lastCharacter === '"') || ($value[0] === "'" && $lastCharacter === "'"))) {
                $value = substr($value, 1, -1);
            }
            if (getenv($name) === false) {
                putenv("{$name}={$value}");
                $_ENV[$name] = $value;
            }
        }
    }

    public static function string($name, $default = null)
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            if ($default !== null) {
                return $default;
            }
            throw new \RuntimeException("Не задана переменная {$name}");
        }
        return $value;
    }

    public static function nullableString($name)
    {
        $value = getenv($name);
        return ($value === false || trim($value) === '') ? null : trim($value);
    }

    public static function int($name, $default)
    {
        $value = getenv($name);
        return ($value === false || $value === '') ? $default : (int) $value;
    }

    public static function csv($name, array $default = array())
    {
        $value = getenv($name);
        if ($value === false || trim($value) === '') {
            return $default;
        }
        $items = array_map('trim', explode(',', $value));
        return array_values(array_filter($items, function ($item) {
            return $item !== '';
        }));
    }
}
