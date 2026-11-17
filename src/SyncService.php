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
        $totals = array('created' => 0, 'updated' => 0, 'unchanged' => 0, 'cells' => 0, 'stale_rows_cleared' => 0);
        $updatedCells = 0;
        $sheetResults = array();
        foreach ($payloadsBySheet as $sheetName => $payloads) {
            $sheetExists = $this->sheets->sheetExists($sheetName);
            if (!$sheetExists && $dryRun) {
                $count = count($payloads);
                $totals['created'] += $count;
                $sheetResults[$sheetName] = array('would_create_sheet' => true, 'deals' => $count, 'created' => $count);
                continue;
            }
            if (!$dryRun) {
                $this->sheets->ensureSheet($sheetName);
            }

            $repository = new SheetRepository(
                $this->sheets,
                $sheetName,
                $this->config['technical_header'],
                $this->config['technical_column'],
                $this->logger,
                isset($this->config['layout_headers']) ? $this->config['layout_headers'] : $this->config['auto_headers']
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
            foreach (array('created', 'updated', 'unchanged', 'cells') as $statName) {
                $totals[$statName] += $plan['stats'][$statName];
            }
            $totals['stale_rows_cleared'] += count($staleRanges);

            if (!$dryRun) {
                if ($plan['writes'] !== array()) {
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
            }

            $sheetResults[$sheetName] = array_merge(
                array('deals' => count($payloads), 'stale_rows_cleared' => count($staleRanges)),
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
}
