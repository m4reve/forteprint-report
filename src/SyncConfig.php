<?php

namespace App;

final class SyncConfig
{
    public static function fromEnv(array &$mapping)
    {
        $technicalColumn = Env::int('TECHNICAL_ID_COLUMN', 12);
        if ($technicalColumn < 1) {
            throw new \RuntimeException('TECHNICAL_ID_COLUMN должен быть положительным номером столбца');
        }

        $autoColumnConfigs = array(
            array('column_env' => 'DEAL_LINK_COLUMN', 'header_env' => 'DEAL_LINK_HEADER', 'column' => 13, 'header' => 'Ссылка на сделку в Битрикс24'),
            array('column_env' => 'DEAL_CREATED_COLUMN', 'header_env' => 'DEAL_CREATED_HEADER', 'column' => 14, 'header' => 'Дата создания сделки в Б24'),
            array('column_env' => 'DEAL_WON_DATE_COLUMN', 'header_env' => 'DEAL_WON_DATE_HEADER', 'column' => 15, 'header' => 'Дата перехода сделки в Успешно реализовано'),
            array('column_env' => 'DEAL_TYPE_COLUMN', 'header_env' => 'DEAL_TYPE_HEADER', 'column' => 16, 'header' => 'Тип сделки'),
            array('column_env' => 'INVOICE_DATE_COLUMN', 'header_env' => 'INVOICE_DATE_HEADER', 'column' => 17, 'header' => 'Дата выставлен счет'),
        );

        $usedColumns = array($technicalColumn => 'TECHNICAL_ID_COLUMN');
        $autoHeaders = array();
        foreach ($autoColumnConfigs as $autoColumnConfig) {
            $column = Env::int($autoColumnConfig['column_env'], $autoColumnConfig['column']);
            $header = Env::string($autoColumnConfig['header_env'], $autoColumnConfig['header']);
            if ($column < 1) {
                throw new \RuntimeException($autoColumnConfig['column_env'] . ' должен быть положительным номером столбца');
            }
            if (isset($usedColumns[$column])) {
                throw new \RuntimeException($usedColumns[$column] . ' и ' . $autoColumnConfig['column_env'] . ' должны указывать на разные столбцы');
            }
            $usedColumns[$column] = $autoColumnConfig['column_env'];
            if ($header !== $autoColumnConfig['header'] && isset($mapping['columns'][$autoColumnConfig['header']])) {
                $mapping['columns'][$header] = $mapping['columns'][$autoColumnConfig['header']];
                unset($mapping['columns'][$autoColumnConfig['header']]);
            }
            $autoHeaders[$column] = $header;
        }

        $layoutHeaders = $autoHeaders;
        $businessColumn = 1;
        foreach (array_keys($mapping['columns']) as $header) {
            if (in_array($header, $autoHeaders, true)) {
                continue;
            }
            while ($businessColumn === $technicalColumn || isset($layoutHeaders[$businessColumn])) {
                $businessColumn++;
            }
            $layoutHeaders[$businessColumn] = $header;
            $businessColumn++;
        }
        ksort($layoutHeaders);

        $stageIds = Env::csv('BITRIX24_STAGE_IDS', array());
        if ($stageIds === array()) {
            $singleStageId = Env::nullableString('BITRIX24_STAGE_ID');
            $stageIds = $singleStageId === null ? array() : array($singleStageId);
        }
        $wonStageId = Env::nullableString('BITRIX24_WON_STAGE_ID');
        if ($wonStageId === null) {
            foreach ($stageIds as $stageId) {
                if ($stageId === 'WON' || substr($stageId, -4) === ':WON') {
                    $wonStageId = $stageId;
                    break;
                }
            }
        }
        if ($wonStageId === null) {
            $wonStageId = 'WON';
        }

        return array(
            'sheet_name' => Env::string('GOOGLE_SHEET_NAME', 'Лист1'),
            'technical_header' => Env::string('TECHNICAL_ID_HEADER', 'Bitrix24 Deal ID'),
            'technical_column' => $technicalColumn,
            'lookback_seconds' => Env::int('SYNC_LOOKBACK_SECONDS', 300),
            'category_id' => Env::nullableString('BITRIX24_CATEGORY_ID'),
            'stage_ids' => array_values(array_unique($stageIds)),
            'invoice_stage_id' => Env::string('BITRIX24_INVOICE_STAGE_ID', 'PREPAYMENT_INVOICE'),
            'won_stage_id' => $wonStageId,
            'auto_headers' => $autoHeaders,
            'layout_headers' => $layoutHeaders,
            'month_source_field' => Env::string('MONTH_SOURCE_FIELD', 'createdTime'),
            'assigned_by_id' => null,
            'remove_stale_rows' => false,
            'replace_sheet_layout' => false,
            'check_sheet_layout' => false,
            'order_header' => 'Номер',
            'hide_technical_column' => false,
            'highlight_changed_cells' => false,
            'freeze_header_row' => Env::int('GOOGLE_FREEZE_HEADER_ROW', 1) === 1,
            'rebuild_layout_env' => '',
        );
    }

    /** Конфигурация отдельной, упрощенной структуры таблиц менеджеров. */
    public static function forManagers(array &$mapping)
    {
        return self::forUnifiedLayout($mapping, 'MANAGER');
    }

    /** Общая таблица с той же структурой, но без фильтра по менеджеру. */
    public static function forCommon(array &$mapping)
    {
        return self::forUnifiedLayout($mapping, 'COMMON');
    }

    private static function forUnifiedLayout(array &$mapping, $envPrefix)
    {
        $visibleColumnCount = count($mapping['columns']);
        $firstFreeColumn = $visibleColumnCount + 1;
        $technicalColumnEnv = $envPrefix . '_TECHNICAL_ID_COLUMN';
        $technicalHeaderEnv = $envPrefix . '_TECHNICAL_ID_HEADER';
        $rebuildLayoutEnv = $envPrefix . '_REBUILD_LAYOUT';
        $highlightEnv = $envPrefix . '_HIGHLIGHT_CHANGED_CELLS';
        $configuredTechnicalColumn = Env::int($technicalColumnEnv, 0);
        // Значение 0 включает автоматический режим. Устаревший номер, который уже
        // занят видимым столбцом, также безопасно переносится в первый свободный.
        $technicalColumn = $configuredTechnicalColumn <= $visibleColumnCount
            ? $firstFreeColumn
            : $configuredTechnicalColumn;

        $layoutHeaders = array();
        $column = 1;
        foreach (array_keys($mapping['columns']) as $header) {
            while ($column === $technicalColumn) {
                $column++;
            }
            $layoutHeaders[$column] = $header;
            $column++;
        }
        if (isset($layoutHeaders[$technicalColumn])) {
            throw new \RuntimeException($technicalColumnEnv . ' пересекается с видимыми столбцами таблицы');
        }

        $stageIds = Env::csv('BITRIX24_STAGE_IDS', array());
        if ($stageIds === array()) {
            $singleStageId = Env::nullableString('BITRIX24_STAGE_ID');
            $stageIds = $singleStageId === null ? array() : array($singleStageId);
        }
        $wonStageId = Env::nullableString('BITRIX24_WON_STAGE_ID');
        if ($wonStageId === null) {
            foreach ($stageIds as $stageId) {
                if ($stageId === 'WON' || substr($stageId, -4) === ':WON') {
                    $wonStageId = $stageId;
                    break;
                }
            }
        }
        if ($wonStageId === null) {
            $wonStageId = 'WON';
        }

        return array(
            'sheet_name' => Env::string('GOOGLE_SHEET_NAME', 'Лист1'),
            'technical_header' => Env::string($technicalHeaderEnv, 'Bitrix24 Deal ID'),
            'technical_column' => $technicalColumn,
            'lookback_seconds' => Env::int('SYNC_LOOKBACK_SECONDS', 300),
            'category_id' => Env::nullableString('BITRIX24_CATEGORY_ID'),
            'stage_ids' => array_values(array_unique($stageIds)),
            'invoice_stage_id' => Env::string('BITRIX24_INVOICE_STAGE_ID', 'PREPAYMENT_INVOICE'),
            'won_stage_id' => $wonStageId,
            'auto_headers' => array(),
            'layout_headers' => $layoutHeaders,
            'month_source_field' => Env::string('MONTH_SOURCE_FIELD', 'createdTime'),
            'assigned_by_id' => null,
            'remove_stale_rows' => true,
            'replace_sheet_layout' => Env::int($rebuildLayoutEnv, 0) === 1,
            'check_sheet_layout' => true,
            'order_header' => '№',
            'hide_technical_column' => true,
            'highlight_changed_cells' => Env::int($highlightEnv, 1) === 1,
            'freeze_header_row' => Env::int('GOOGLE_FREEZE_HEADER_ROW', 1) === 1,
            'rebuild_layout_env' => $rebuildLayoutEnv,
        );
    }
}
