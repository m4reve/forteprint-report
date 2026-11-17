<?php

namespace App;

final class FieldMapper
{
    private $fieldByTitle = array();
    private $fieldDefinitions;
    private $mapping;
    private $bitrix;
    private $categoryNames;
    private $sourceNames;

    public function __construct(array $fieldDefinitions, array $mapping, Bitrix24Client $bitrix, array $categoryNames, array $sourceNames)
    {
        $this->fieldDefinitions = $fieldDefinitions;
        $this->mapping = $mapping;
        $this->bitrix = $bitrix;
        $this->categoryNames = $categoryNames;
        $this->sourceNames = $sourceNames;
        foreach ($fieldDefinitions as $code => $definition) {
            $rawTitle = isset($definition['title']) ? $definition['title'] : (isset($definition['formLabel']) ? $definition['formLabel'] : '');
            $title = $this->normalize((string) $rawTitle);
            if ($title !== '') {
                $this->fieldByTitle[$title] = $code;
            }
        }
    }

    public function requiredCustomFieldCodes()
    {
        $codes = [];
        foreach ($this->mapping['columns'] as $rule) {
            $type = isset($rule['type']) ? $rule['type'] : '';
            if ($type === 'custom') {
                $code = $this->customCode((string) (isset($rule['title']) ? $rule['title'] : ''));
                if ($code !== null) {
                    $codes[] = $code;
                }
            } elseif ($type === 'custom_code' && !empty($rule['field'])) {
                $codes[] = (string) $rule['field'];
            }
        }
        $jobSpecParts = isset($this->mapping['job_spec_parts']) ? $this->mapping['job_spec_parts'] : array();
        foreach ($jobSpecParts as $source) {
            $code = $this->customCode((string) (isset($source['title']) ? $source['title'] : ''));
            if ($code !== null) {
                $codes[] = $code;
            }
        }
        return array_values(array_unique($codes));
    }

    public function unresolvedCustomFields()
    {
        $missing = [];
        foreach ($this->mapping['columns'] as $column => $rule) {
            $type = isset($rule['type']) ? $rule['type'] : '';
            if ($type === 'custom' && $this->customCode((string) $rule['title']) === null) {
                $missing[$column] = (string) $rule['title'];
            } elseif ($type === 'custom_code') {
                $code = (string) (isset($rule['field']) ? $rule['field'] : '');
                if ($code === '' || !isset($this->fieldDefinitions[$code])) {
                    $missing[$column] = $code === '' ? '(код не задан)' : $code;
                }
            }
        }
        $jobSpecParts = isset($this->mapping['job_spec_parts']) ? $this->mapping['job_spec_parts'] : array();
        foreach ($jobSpecParts as $part) {
            $title = (string) (isset($part['title']) ? $part['title'] : '');
            $label = (string) (isset($part['label']) ? $part['label'] : $title);
            if ($title !== '' && $this->customCode($title) === null) {
                $missing['ТЗ / ' . $label] = $title;
            }
        }
        return $missing;
    }

    /** @return array{managed: bool, value: mixed} */
    public function valueFor($column, array $deal, array $client)
    {
        $rule = isset($this->mapping['columns'][$column]) ? $this->mapping['columns'][$column] : array('type' => 'unmanaged');
        $type = isset($rule['type']) ? $rule['type'] : 'unmanaged';
        if ($type === 'unmanaged') {
            return ['managed' => false, 'value' => null];
        }

        if ($type === 'computed') {
            $computed = $this->computed((string) (isset($rule['name']) ? $rule['name'] : ''), $deal, $client);
            return ['managed' => true, 'value' => $computed];
        }

        $code = null;
        if ($type === 'field' || $type === 'custom_code') {
            $code = (string) (isset($rule['field']) ? $rule['field'] : '');
        } elseif ($type === 'custom') {
            $code = $this->customCode((string) (isset($rule['title']) ? $rule['title'] : ''));
            if ($code === null) {
                return ['managed' => false, 'value' => null];
            }
        }

        $value = $code !== null && isset($deal[$code]) ? $deal[$code] : null;
        if (($value === null || $value === '') && isset($rule['fallback_field'])) {
            $value = isset($deal[$rule['fallback_field']]) ? $deal[$rule['fallback_field']] : '';
        }
        $value = $this->lookup($value, (string) (isset($rule['lookup']) ? $rule['lookup'] : ''));
        $value = $this->format($value, (string) (isset($rule['format']) ? $rule['format'] : ''), $code);
        return ['managed' => true, 'value' => $value];
    }

    private function customCode($title)
    {
        $key = $this->normalize($title);
        return isset($this->fieldByTitle[$key]) ? $this->fieldByTitle[$key] : null;
    }

    private function computed($name, array $deal, array $client)
    {
        if ($name === 'client_name') {
            return isset($client['name']) ? $client['name'] : '';
        }
        if ($name === 'client_email') {
            return isset($client['email']) ? $client['email'] : '';
        }
        if ($name === 'job_spec') {
            $result = array();
            $parts = isset($this->mapping['job_spec_parts']) ? $this->mapping['job_spec_parts'] : array();
            foreach ($parts as $part) {
                $title = (string) (isset($part['title']) ? $part['title'] : '');
                $code = $this->customCode($title);
                $value = $code !== null && isset($deal[$code]) ? $deal[$code] : null;
                if ($value === null || $value === '' || $value === array()) {
                    continue;
                }
                $value = $this->format($value, '', $code);
                $label = (string) (isset($part['label']) ? $part['label'] : $title);
                $suffix = (string) (isset($part['suffix']) ? $part['suffix'] : '');
                $result[] = $label . ': ' . $value . $suffix;
            }
            return implode('; ', $result);
        }
        if ($name === 'deal_url') {
            return $this->bitrix->dealUrl(isset($deal['id']) ? $deal['id'] : 0);
        }
        if ($name === 'won_date') {
            return $this->format(isset($deal['_wonStageDate']) ? $deal['_wonStageDate'] : '', 'date_text', null);
        }
        if ($name === 'invoice_date') {
            return $this->format(isset($deal['_invoiceStageDate']) ? $deal['_invoiceStageDate'] : '', 'date_text', null);
        }
        if ($name === 'messenger_name' || $name === 'messenger_value') {
            $messengers = isset($this->mapping['messenger_priority']) ? $this->mapping['messenger_priority'] : array();
            foreach ($messengers as $source) {
                $code = $this->customCode((string) (isset($source['title']) ? $source['title'] : ''));
                $value = $code !== null && isset($deal[$code]) ? $deal[$code] : null;
                if ($value !== null && $value !== '' && $value !== []) {
                    return $name === 'messenger_name'
                        ? (string) (isset($source['label']) ? $source['label'] : '')
                        : $this->format($value, '', $code);
                }
            }
            return '';
        }
        return '';
    }

    private function lookup($value, $lookup)
    {
        if ($value === null || $value === '') {
            return '';
        }
        if ($lookup === 'category') {
            return isset($this->categoryNames[(string) $value]) ? $this->categoryNames[(string) $value] : (string) $value;
        }
        if ($lookup === 'source') {
            return isset($this->sourceNames[(string) $value]) ? $this->sourceNames[(string) $value] : (string) $value;
        }
        if ($lookup === 'user') {
            return $this->bitrix->userName($value);
        }
        return $value;
    }

    private function format($value, $format, $code)
    {
        if ($value === null) {
            return '';
        }
        if ($code !== null) {
            $definition = isset($this->fieldDefinitions[$code]) ? $this->fieldDefinitions[$code] : array();
            if ((isset($definition['type']) ? $definition['type'] : '') === 'enumeration') {
                $items = isset($definition['items']) ? $definition['items'] : array();
                $enum = [];
                foreach ($items as $item) {
                    $id = isset($item['ID']) ? $item['ID'] : (isset($item['id']) ? $item['id'] : '');
                    $enum[(string) $id] = (string) (isset($item['VALUE']) ? $item['VALUE'] : (isset($item['value']) ? $item['value'] : $id));
                }
                if (is_array($value)) {
                    $value = array_map(function ($v) use ($enum) {
                        return isset($enum[(string) $v]) ? $enum[(string) $v] : (string) $v;
                    }, $value);
                } else {
                    $value = isset($enum[(string) $value]) ? $enum[(string) $value] : $value;
                }
            }
        }
        if ($format === 'boolean') {
            return in_array($value, [true, 1, '1', 'Y', 'y', 'true'], true) ? 'Да' : 'Нет';
        }
        if ($format === 'date_text' && $value !== '') {
            try {
                $date = new \DateTime((string) $value);
                return $date->format('d.m.Y');
            } catch (\Exception $error) {
                return (string) $value;
            }
        }
        if ($format === 'datetime_text' && $value !== '') {
            try {
                $date = new \DateTime((string) $value);
                return $date->format('d.m.Y H:i:s');
            } catch (\Exception $error) {
                return (string) $value;
            }
        }
        if (($format === 'date' || $format === 'datetime') && $value !== '') {
            try {
                $date = new \DateTime((string) $value);
                return $this->googleDateSerial($date, $format === 'datetime');
            } catch (\Exception $error) {
                return (string) $value;
            }
        }
        if ($format === 'number' && $value !== '') {
            if (is_string($value) && strpos($value, '|') !== false) {
                $value = explode('|', $value, 2)[0];
            }
            return is_numeric($value) ? (float) $value : $value;
        }
        if (is_array($value)) {
            $flat = array_map(function ($v) {
                return is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }, $value);
            return implode(', ', $flat);
        }
        if (is_bool($value)) {
            return $value ? 'Да' : 'Нет';
        }
        return $value;
    }

    private function googleDateSerial(\DateTime $date, $includeTime)
    {
        // Google Sheets использует ту же базовую дату, что и Excel: 1899-12-30.
        // Компоненты времени берутся в часовом поясе значения Bitrix24, чтобы в листе
        // отображалось то же локальное время, а не сдвинутое UTC-время.
        $utc = new \DateTimeZone('UTC');
        $wallClock = \DateTime::createFromFormat(
            '!Y-m-d H:i:s',
            $includeTime ? $date->format('Y-m-d H:i:s') : $date->format('Y-m-d 00:00:00'),
            $utc
        );
        if ($wallClock === false) {
            return $date->format('Y-m-d');
        }
        $base = new \DateTime('1899-12-30 00:00:00', $utc);
        $serial = ($wallClock->getTimestamp() - $base->getTimestamp()) / 86400;
        return $includeTime ? $serial : (int) round($serial);
    }

    private function normalize($value)
    {
        $normalized = preg_replace('/\s+/u', ' ', $value);
        return mb_strtolower(trim($normalized === null ? $value : $normalized));
    }
}
