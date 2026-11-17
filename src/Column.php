<?php

namespace App;

final class Column
{
    public static function letters($number)
    {
        if ($number < 1) {
            throw new \InvalidArgumentException('Номер столбца должен быть положительным');
        }
        $letters = '';
        while ($number > 0) {
            $number--;
            $letters = chr(65 + ($number % 26)) . $letters;
            $number = (int) floor($number / 26);
        }
        return $letters;
    }

    public static function quoteSheet($name)
    {
        return "'" . str_replace("'", "''", $name) . "'";
    }
}
