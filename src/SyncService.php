<?php

namespace App;

final class SyncService
{
    private $bitrix;
    private $sheets;
    private $state;
    private $mapping;
    private $config;
    private $logger;

    public function __construct(Bitrix24Client $bitrix, GoogleSheetsClient $sheets, StateStore $state, array $mapping, array $config, Logger $logger)
    {
        $this->bitrix = $bitrix;
        $this->sheets = $sheets;
        $this->state = $state;
        $this->mapping = $mapping;
        $this->config = $config;
        $this->logger = $logger;
    }

    public function run($full, $dryRun, $diagnose)
    {
        $startedAt = new \DateTime('now', new \DateTimeZone('UTC'));
        $definitions = $this->bitrix->dealFields();
        $categories = $this->bitrix->categoryNames();
        $sources = $this->bitrix->sourceNames();
        $categoryId = $this->config['category_id'] === null ? 0 : (int) $this->config['category_id'];
        $stageIds = $this->config['stage_ids'];
        if ($stageIds === array()) {
            throw new \RuntimeException('Не заданы BITRIX24_STAGE_IDS для синхронизации сделок');
        }
        $mapper = new FieldMapper($definitions, $this->mapping, $this->bitrix, $categories, $sources);
        $unresolved = $mapper->unresolvedCustomFields();
        foreach ($unresolved as $column => $field) {
            $this->logger->warning('Пользовательское поле Bitrix24 не найдено; столбец останется без изменений', [
                'sheet_column' => $column,
                'bitrix_field_title' => $field,
            ]);
        }

        if ($diagnose) {
            return [
                'status' => 'ok',
                'mode' => 'diagnose',
                'deal_fields' => count($definitions),
                'category_id' => $categoryId,
                'stage_ids' => $stageIds,
                'google_sheets' => $this->sheets->listSheetNames(),
                'unresolved_custom_fields' => $unresolved,
            ];
        }

        $since = null;
        if (!$full) {
            $last = $this->state->lastSuccessfulSync();
            if ($last !== null) {
                $lastDate = new \DateTime($last);
                $lastDate->modify('-' . $this->config['lookback_seconds'] . ' seconds');
                $since = $lastDate->format(DATE_ATOM);
            }
        }
        $this->logger->info('Получение сделок Bitrix24', [
            'updated_since' => $since,
            'full' => $full,
            'category_id' => $categoryId,
            'stage_ids' => $stageIds,
        ]);
        $monthSourceField = isset($this->config['month_source_field']) ? $this->config['month_source_field'] : 'createdTime';
        $selectedFields = $mapper->requiredCustomFieldCodes();
        if (substr($monthSourceField, 0, 1) !== '_') {
            $selectedFields[] = $monthSourceField;
        }
        $deals = $this->bitrix->listDeals(
            array_values(array_unique($selectedFields)),
            $since,
            $categoryId,
            $stageIds,
            isset($this->config['assigned_by_id']) ? $this->config['assigned_by_id'] : null
        );

        $dealIds = array();
        foreach ($deals as $deal) {
            if (!empty($deal['id'])) {
                $dealIds[] = $deal['id'];
            }
        }
        $invoiceStageId = $this->config['invoice_stage_id'];
        $wonStageId = $this->config['won_stage_id'];
        $stageDates = $this->bitrix->stageEnteredDates($dealIds, array($invoiceStageId, $wonStageId));

        $payloadsBySheet = array();
        foreach ($deals as $deal) {
            $dealId = isset($deal['id']) ? (string) $deal['id'] : '';
            $deal['_invoiceStageDate'] = isset($stageDates[$invoiceStageId][$dealId]) ? $stageDates[$invoiceStageId][$dealId] : '';
            $deal['_wonStageDate'] = isset($stageDates[$wonStageId][$dealId]) ? $stageDates[$wonStageId][$dealId] : '';
            $monthKey = MonthlySheet::keyForDeal($deal, $monthSourceField);
            $sheetName = MonthlySheet::nameForKey($monthKey);
            if (!isset($payloadsBySheet[$sheetName])) {
                $payloadsBySheet[$sheetName] = array();
            }
            $payloadsBySheet[$sheetName][] = ['deal' => $deal, 'client' => $this->bitrix->clientForDeal($deal)];
        }

        $removeStaleRows = !empty($this->config['remove_stale_rows']);
        if ($removeStaleRows) {
            foreach ($this->sheets->listSheetNames() as $existingSheetName) {
                if (MonthlySheet::isMonthlyName($existingSheetName) && !isset($payloadsBySheet[$existingSheetName])) {
                    $payloadsBySheet[$existingSheetName] = array();
                }
            }
        }

        uksort($payloadsBySheet, function ($left, $right) {
            return strcmp(MonthlySheet::sortKeyForName($left), MonthlySheet::sortKeyForName($right));
        });
        $totals = array('created' => 0, 'updated' => 0, 'unchanged' => 0, 'cells' => 0, 'stale_rows_cleared' => 0, 'highlighted_cells' => 0);
        $updatedCells = 0;
        $sheetResults = array();
        foreach ($payloadsBySheet as $sheetName => $payloads) {
            $sheetExists = $this->sheets->sheetExists($sheetName);
            $layoutReplaced = false;
            if (!$sheetExists && $dryRun) {
                $count = count($payloads);
                $totals['created'] += $count;
                $sheetResults[$sheetName] = array('would_create_sheet' => true, 'deals' => $count, 'created' => $count);
                continue;
            }
            if (!$dryRun) {
                $frozenRowCount = !empty($this->config['freeze_header_row']) ? 1 : 0;
                $this->sheets->ensureSheet($sheetName, $frozenRowCount);
                $this->sheets->ensureFrozenRows($sheetName, $frozenRowCount);
            }

            if ($sheetExists && !empty($this->config['check_sheet_layout'])) {
                $headerRows = $this->sheets->getValues(Column::quoteSheet($sheetName) . '!A1:AZ1');
                $actualHeaders = isset($headerRows[0]) ? $headerRows[0] : array();
                if (!$this->layoutMatches($actualHeaders)) {
                    if ($dryRun) {
                        $count = count($payloads);
                        $totals['created'] += $count;
                        $sheetResults[$sheetName] = array(
                            'would_replace_layout' => true,
                            'deals' => $count,
                            'created' => $count,
                        );
                        continue;
                    }
                    if (empty($this->config['replace_sheet_layout'])) {
                        $rebuildLayoutEnv = isset($this->config['rebuild_layout_env']) && $this->config['rebuild_layout_env'] !== ''
                            ? $this->config['rebuild_layout_env']
                            : 'MANAGER_REBUILD_LAYOUT';
                        throw new \RuntimeException(
                            "Лист «{$sheetName}» использует старую структуру. Сделайте резервную копию, "
                            . "установите {$rebuildLayoutEnv}=1 и повторите синхронизацию."
                        );
                    }
                    $this->sheets->batchClear(array(Column::quoteSheet($sheetName) . '!A:AZ'));
                    $layoutReplaced = true;
                    $this->logger->warning('Старая структура менеджерского листа очищена для перестройки', array(
                        'sheet' => $sheetName,
                    ));
                }
            }
            if (!$dryRun && !empty($this->config['hide_technical_column']) && (!$sheetExists || $layoutReplaced)) {
                $this->sheets->setColumnHidden($sheetName, $this->config['technical_column'], true);
            }

            $repository = new SheetRepository(
                $this->sheets,
                $sheetName,
                $this->config['technical_header'],
                $this->config['technical_column'],
                $this->logger,
                isset($this->config['layout_headers']) ? $this->config['layout_headers'] : $this->config['auto_headers'],
                isset($this->config['order_header']) ? $this->config['order_header'] : 'Номер'
            );
            $repository->load(!$dryRun);
            $missingHeaders = $repository->missingHeaders(array_keys($this->mapping['columns']));
            if ($missingHeaders !== array()) {
                throw new \RuntimeException("В листе «{$sheetName}» не найдены столбцы: " . implode(', ', $missingHeaders));
            }

            $plan = $repository->planWrites($payloads, $mapper);
            $currentDealIds = array();
            foreach ($payloads as $payload) {
                if (!empty($payload['deal']['id'])) {
                    $currentDealIds[] = $payload['deal']['id'];
                }
            }
            $staleRanges = $removeStaleRows ? $repository->staleRowRanges($currentDealIds) : array();
            $highlightCells = !empty($this->config['highlight_changed_cells'])
                ? $plan['highlight_cells']
                : array();
            foreach (array('created', 'updated', 'unchanged', 'cells') as $statName) {
                $totals[$statName] += $plan['stats'][$statName];
            }
            $totals['stale_rows_cleared'] += count($staleRanges);
            $totals['highlighted_cells'] += count($highlightCells);

            if (!$dryRun) {
                if ($plan['writes'] !== array() || $plan['formula_writes'] !== array()) {
                    $addedRows = $this->sheets->ensureRowCapacity($sheetName, $plan['required_rows']);
                    if ($addedRows > 0) {
                        $this->logger->info('Google Sheets автоматически расширен', array(
                            'sheet' => $sheetName,
                            'added_rows' => $addedRows,
                            'required_rows' => $plan['required_rows'],
                        ));
                    }
                }
                // Сначала очищаем строки сделок, больше не принадлежащих менеджеру,
                // затем применяем записи: строка может быть безопасно переиспользована по номеру заказа.
                foreach (array_chunk($staleRanges, 500) as $rangeChunk) {
                    $this->sheets->batchClear($rangeChunk);
                }
                foreach (array_chunk($plan['writes'], 500) as $chunk) {
                    $updatedCells += $this->sheets->batchUpdate($chunk);
                }
                foreach (array_chunk($plan['formula_writes'], 500) as $chunk) {
                    $updatedCells += $this->sheets->batchUpdate($chunk, 'USER_ENTERED');
                }
                foreach (array_chunk($highlightCells, 500) as $cellChunk) {
                    $this->sheets->highlightCells($cellChunk, $sheetName);
                }
            }

            $sheetResults[$sheetName] = array_merge(
                array(
                    'deals' => count($payloads),
                    'stale_rows_cleared' => count($staleRanges),
                    'highlighted_cells' => count($highlightCells),
                ),
                $plan['stats']
            );
        }

        $this->logger->info('План помесячной синхронизации сформирован', array_merge($totals, array('deals' => count($deals), 'sheets' => count($sheetResults))));
        if (!$dryRun) {
            $this->state->save($startedAt->format(DATE_ATOM));
        }

        return array_merge(array(
            'status' => 'ok',
            'mode' => $dryRun ? 'dry-run' : ($full ? 'full' : 'incremental'),
            'deals_read' => count($deals),
            'updated_cells' => $updatedCells,
            'unresolved_custom_fields' => $unresolved,
            'month_source_field' => $monthSourceField,
            'sheets' => $sheetResults,
        ), $totals);
    }

    private function layoutMatches(array $actualHeaders)
    {
        $expected = array();
        $layoutHeaders = isset($this->config['layout_headers']) ? $this->config['layout_headers'] : array();
        foreach ($layoutHeaders as $column => $header) {
            $expected[(int) $column - 1] = $this->normalizeHeader($header);
        }
        $expected[(int) $this->config['technical_column'] - 1] = $this->normalizeHeader($this->config['technical_header']);

        $lastIndex = max(
            $actualHeaders === array() ? 0 : count($actualHeaders) - 1,
            $expected === array() ? 0 : max(array_keys($expected))
        );
        for ($index = 0; $index <= $lastIndex; $index++) {
            $actual = $this->normalizeHeader(isset($actualHeaders[$index]) ? $actualHeaders[$index] : '');
            $wanted = isset($expected[$index]) ? $expected[$index] : '';
            if ($actual !== $wanted) {
                return false;
            }
        }
        return true;
    }

    private function normalizeHeader($header)
    {
        $header = str_replace("\xC2\xA0", ' ', (string) $header);
        $header = preg_replace('/\s+/u', ' ', $header);
        return trim($header === null ? '' : $header);
    }
}
