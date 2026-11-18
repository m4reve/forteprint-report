<?php

namespace App;

final class FieldMapper
{
    private $fieldByTitle = array();
    private $fieldBySymbolicCode = array();
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
            $normalizedCode = $this->normalizeSymbolicCode($code);
            if ($normalizedCode !== '') {
                $this->fieldBySymbolicCode[$normalizedCode] = (string) $code;
            }
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
            if ($type === 'custom' || $type === 'custom_code') {
                $code = $this->customCodeForRule($rule);
                if ($code !== null) {
                    $codes[] = $code;
                }
            }
        }
        $jobSpecParts = isset($this->mapping['job_spec_parts']) ? $this->mapping['job_spec_parts'] : array();
        foreach ($jobSpecParts as $source) {
            $code = $this->customCodeForRule($source);
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
            if (($type === 'custom' || $type === 'custom_code') && $this->customCodeForRule($rule) === null) {
                $missing[$column] = $this->customRuleDescription($rule);
            }
        }
        $jobSpecParts = isset($this->mapping['job_spec_parts']) ? $this->mapping['job_spec_parts'] : array();
        foreach ($jobSpecParts as $part) {
            $description = $this->customRuleDescription($part);
            $label = (string) (isset($part['label']) ? $part['label'] : $description);
            if ($description !== '(поле не задано)' && $this->customCodeForRule($part) === null) {
                $missing['ТЗ / ' . $label] = $description;
            }
        }
        return $missing;
    }

    /** @return array{managed: bool, value: mixed, input_option?: string} */
    public function valueFor($column, array $deal, array $client)
    {
        $rule = isset($this->mapping['columns'][$column]) ? $this->mapping['columns'][$column] : array('type' => 'unmanaged');
        $type = isset($rule['type']) ? $rule['type'] : 'unmanaged';
        if ($type === 'unmanaged') {
            return ['managed' => false, 'value' => null];
        }

        if ($type === 'row_formula') {
            return array(
                'managed' => true,
                'value' => (string) (isset($rule['formula']) ? $rule['formula'] : ''),
                'input_option' => 'USER_ENTERED',
            );
        }

        if ($type === 'computed') {
            $computed = $this->computed((string) (isset($rule['name']) ? $rule['name'] : ''), $deal, $client);
            return ['managed' => true, 'value' => $computed];
        }

        $code = null;
        if ($type === 'field') {
            $code = (string) (isset($rule['field']) ? $rule['field'] : '');
        } elseif ($type === 'custom' || $type === 'custom_code') {
            $code = $this->customCodeForRule($rule);
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
        if (isset($rule['formula_multiplier']) && $value !== '' && is_numeric($value)) {
            $multiplier = (float) $rule['formula_multiplier'];
            return array(
                'managed' => true,
                'value' => '=' . $this->formulaNumber($value) . '*' . $this->formulaNumber($multiplier),
                'input_option' => 'USER_ENTERED',
            );
        }
        return ['managed' => true, 'value' => $value, 'input_option' => 'RAW'];
    }

    private function customCode($title)
    {
        $key = $this->normalize($title);
        return isset($this->fieldByTitle[$key]) ? $this->fieldByTitle[$key] : null;
    }

    private function customCodeForRule(array $rule)
    {
        $symbolicCodes = array();
        if (isset($rule['field']) && trim((string) $rule['field']) !== '') {
            $symbolicCodes[] = (string) $rule['field'];
        }
        if (isset($rule['fields']) && is_array($rule['fields'])) {
            foreach ($rule['fields'] as $field) {
                if (trim((string) $field) !== '') {
                    $symbolicCodes[] = (string) $field;
                }
            }
        }
        foreach (array_unique($symbolicCodes) as $symbolicCode) {
            $normalizedCode = $this->normalizeSymbolicCode($symbolicCode);
            if ($normalizedCode !== '' && isset($this->fieldBySymbolicCode[$normalizedCode])) {
                return $this->fieldBySymbolicCode[$normalizedCode];
            }
        }
        // Если код явно указан, он является авторитетным. По названию не подменяем.
        if ($symbolicCodes !== array()) {
            return null;
        }

        $titles = array();
        if (isset($rule['title']) && trim((string) $rule['title']) !== '') {
            $titles[] = (string) $rule['title'];
        }
        if (isset($rule['titles']) && is_array($rule['titles'])) {
            foreach ($rule['titles'] as $title) {
                if (trim((string) $title) !== '') {
                    $titles[] = (string) $title;
                }
            }
        }
        foreach (array_unique($titles) as $title) {
            $code = $this->customCode($title);
            if ($code !== null) {
                return $code;
            }
        }
        return null;
    }

    private function customRuleDescription(array $rule)
    {
        $codes = array();
        if (isset($rule['field']) && trim((string) $rule['field']) !== '') {
            $codes[] = (string) $rule['field'];
        }
        if (isset($rule['fields']) && is_array($rule['fields'])) {
            $codes = array_merge($codes, $rule['fields']);
        }
        $codes = array_values(array_unique(array_filter(array_map('strval', $codes))));

        $titles = array();
        if (isset($rule['title']) && trim((string) $rule['title']) !== '') {
            $titles[] = (string) $rule['title'];
        }
        if (isset($rule['titles']) && is_array($rule['titles'])) {
            $titles = array_merge($titles, $rule['titles']);
        }
        $titles = array_values(array_unique(array_filter(array_map('strval', $titles))));
        $parts = array();
        if ($codes !== array()) {
            $parts[] = 'код: ' . implode(' / ', $codes);
        }
        if ($titles !== array()) {
            $parts[] = 'название: ' . implode(' / ', $titles);
        }
        return $parts === array() ? '(поле не задано)' : implode('; ', $parts);
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
                $code = $this->customCodeForRule($part);
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
        if ($name === 'contact_url') {
            $contactIds = isset($deal['contactIds']) ? $deal['contactIds'] : array();
            if (!is_array($contactIds)) {
                $contactIds = array($contactIds);
            }
            if ($contactIds === array() && !empty($deal['contactId'])) {
                $contactIds[] = $deal['contactId'];
            }
            foreach ($contactIds as $contactId) {
                if ((int) $contactId > 0) {
                    return $this->bitrix->contactUrl($contactId);
                }
            }
            return '';
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
                $code = $this->customCodeForRule($source);
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

    private function formulaNumber($value)
    {
        $formatted = number_format((float) $value, 10, '.', '');
        $formatted = rtrim(rtrim($formatted, '0'), '.');
        if ($formatted === '' || $formatted === '-0') {
            return '0';
        }

        // Таблицы менеджеров используют русскую локаль Google Sheets,
        // поэтому десятичным разделителем внутри формулы должна быть запятая.
        return str_replace('.', ',', $formatted);
    }

    private function normalize($value)
    {
        $normalized = preg_replace('/\s+/u', ' ', $value);
        return mb_strtolower(trim($normalized === null ? $value : $normalized));
    }

    private function normalizeSymbolicCode($code)
    {
        $normalized = preg_replace('/[^a-z0-9]+/i', '', (string) $code);
        return strtolower($normalized === null ? '' : $normalized);
    }
}
