<?php

namespace App;

final class MonthlySheet
{
    private static $months = array(
        1 => 'Январь',
        2 => 'Февраль',
        3 => 'Март',
        4 => 'Апрель',
        5 => 'Май',
        6 => 'Июнь',
        7 => 'Июль',
        8 => 'Август',
        9 => 'Сентябрь',
        10 => 'Октябрь',
        11 => 'Ноябрь',
        12 => 'Декабрь',
    );

    public static function keyForDeal(array $deal, $sourceField)
    {
        $value = isset($deal[$sourceField]) ? $deal[$sourceField] : null;
        if ($value === null || $value === '') {
            return 'unknown';
        }
        try {
            $date = new \DateTime((string) $value);
            return $date->format('Y-m');
        } catch (\Exception $error) {
            return 'unknown';
        }
    }

    public static function nameForKey($key)
    {
        if ($key === 'unknown') {
            return 'Без даты';
        }
        if (!preg_match('/^(\d{4})-(\d{2})$/', (string) $key, $matches)) {
            return 'Без даты';
        }
        $month = (int) $matches[2];
        if (!isset(self::$months[$month])) {
            return 'Без даты';
        }
        return self::$months[$month] . ' ' . $matches[1];
    }

    public static function isMonthlyName($name)
    {
        if ((string) $name === 'Без даты') {
            return true;
        }
        $months = implode('|', array_values(self::$months));
        return preg_match('/^(' . $months . ')\s+\d{4}$/u', (string) $name) === 1;
    }

    public static function sortKeyForName($name)
    {
        if ((string) $name === 'Без даты') {
            return '9999-99';
        }
        foreach (self::$months as $monthNumber => $monthName) {
            if (preg_match('/^' . preg_quote($monthName, '/') . '\s+(\d{4})$/u', (string) $name, $matches)) {
                return $matches[1] . '-' . str_pad((string) $monthNumber, 2, '0', STR_PAD_LEFT);
            }
        }
        return '9999-98-' . (string) $name;
    }
}
