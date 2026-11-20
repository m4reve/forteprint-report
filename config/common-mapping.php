<?php

/*
 * Общая таблица использует структуру менеджерских таблиц как основу,
 * но дополнительно показывает ответственного по сделке после имени клиента.
 * Старый маппинг сохранен отдельно в config/mapping.php.
 */
$mapping = require __DIR__ . '/manager-mapping.php';
$columns = array();

foreach ($mapping['columns'] as $header => $rule) {
    $columns[$header] = $rule;
    if ($header === 'Имя') {
        $columns['Менеджер'] = array(
            'type' => 'field',
            'field' => 'assignedById',
            'lookup' => 'user',
        );
    }
}

$mapping['columns'] = $columns;
return $mapping;
