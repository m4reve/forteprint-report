<?php

namespace App;

final class SheetRepository
{
    private $headers = array();
    private $headerColumns = array();
    private $rows = array();
    private $technicalColumn;
    private $sheets;
    private $sheetName;
    private $technicalHeader;
    private $logger;
    private $autoHeaders;

    public function __construct(GoogleSheetsClient $sheets, $sheetName, $technicalHeader, $technicalColumn, Logger $logger, array $autoHeaders = array())
    {
        $this->sheets = $sheets;
        $this->sheetName = $sheetName;
        $this->technicalHeader = $technicalHeader;
        $this->technicalColumn = $technicalColumn;
        $this->logger = $logger;
        $this->autoHeaders = $autoHeaders;
    }

    public function load($allowHeaderWrite)
    {
        $sheet = Column::quoteSheet($this->sheetName);
        $headerRows = $this->sheets->getValues("{$sheet}!A1:AZ1");
        $this->headers = isset($headerRows[0]) ? $headerRows[0] : array();
        $pendingHeaderWrites = array();
        $createdHeaders = array();
        $currentTech = $this->normalizeHeader(isset($this->headers[$this->technicalColumn - 1]) ? $this->headers[$this->technicalColumn - 1] : '');

        if ($currentTech === '') {
            if (!$allowHeaderWrite) {
                $this->logger->warning('Технический заголовок пока не создан', [
                    'column' => Column::letters($this->technicalColumn),
                    'header' => $this->technicalHeader,
                ]);
            } else {
                $cell = Column::letters($this->technicalColumn) . '1';
                $pendingHeaderWrites[] = array('range' => "{$sheet}!{$cell}", 'values' => array(array($this->technicalHeader)));
                $this->headers[$this->technicalColumn - 1] = $this->technicalHeader;
                $createdHeaders[] = array('cell' => $cell, 'header' => $this->technicalHeader, 'technical' => true);
            }
        } elseif ($currentTech !== $this->normalizeHeader($this->technicalHeader)) {
            throw new \RuntimeException(
                'Столбец ' . Column::letters($this->technicalColumn) . " уже занят заголовком «{$currentTech}». "
                . 'Измените TECHNICAL_ID_COLUMN.'
            );
        }

        foreach ($this->autoHeaders as $columnNumber => $header) {
            $columnNumber = (int) $columnNumber;
            $normalizedHeader = $this->normalizeHeader($header);
            if ($columnNumber < 1 || $normalizedHeader === '') {
                continue;
            }
            $alreadyPresent = false;
            foreach ($this->headers as $existingHeader) {
                if ($this->normalizeHeader($existingHeader) === $normalizedHeader) {
                    $alreadyPresent = true;
                    break;
                }
            }
            if ($alreadyPresent) {
                continue;
            }
            $current = $this->normalizeHeader(isset($this->headers[$columnNumber - 1]) ? $this->headers[$columnNumber - 1] : '');
            if ($current !== '') {
                throw new \RuntimeException(
                    'Столбец ' . Column::letters($columnNumber) . " уже занят заголовком «{$current}». "
                    . 'Измените номер автоматически создаваемого столбца в .env или добавьте заголовок вручную.'
                );
            }
            $cell = Column::letters($columnNumber) . '1';
            if ($allowHeaderWrite) {
                $pendingHeaderWrites[] = array('range' => "{$sheet}!{$cell}", 'values' => array(array($header)));
                $createdHeaders[] = array('cell' => $cell, 'header' => $header, 'technical' => false);
            } else {
                $this->logger->warning('Столбец будет создан при рабочем запуске', ['cell' => $cell, 'header' => $header]);
            }
            $this->headers[$columnNumber - 1] = $header;
        }

        // Все отсутствующие заголовки листа создаются одним write-запросом к API.
        if ($pendingHeaderWrites !== array()) {
            $this->sheets->batchUpdate($pendingHeaderWrites);
            foreach ($createdHeaders as $createdHeader) {
                if ($createdHeader['technical']) {
                    $this->logger->info('Создан технический столбец для стабильного ID сделки', array('cell' => $createdHeader['cell']));
                } else {
                    $this->logger->info('Создан столбец Google Sheets', array(
                        'cell' => $createdHeader['cell'],
                        'header' => $createdHeader['header'],
                    ));
                }
            }
        }

        foreach ($this->headers as $index => $header) {
            $header = $this->normalizeHeader($header);
            if ($header !== '') {
                if (isset($this->headerColumns[$header])) {
                    throw new \RuntimeException("В таблице повторяется заголовок «{$header}»");
                }
                $this->headerColumns[$header] = $index + 1;
            }
        }

        $autoColumns = array_keys($this->autoHeaders);
        $lastAutoColumn = $autoColumns === array() ? 0 : max($autoColumns);
        $lastColumn = max(count($this->headers), $this->technicalColumn, $lastAutoColumn);
        $this->rows = $this->sheets->getValues("{$sheet}!A2:" . Column::letters($lastColumn));
    }

    public function missingHeaders(array $expected)
    {
        $columns = $this->headerColumns;
        return array_values(array_filter($expected, function ($header) use ($columns) {
            $header = str_replace("\xC2\xA0", ' ', (string) $header);
            $header = preg_replace('/\s+/u', ' ', $header);
            return !isset($columns[trim($header)]);
        }));
    }

    public function planWrites(array $deals, FieldMapper $mapper)
    {
        $techIndex = $this->technicalColumn - 1;
        $dealRows = [];
        $orderRows = [];
        $orderColumn = isset($this->headerColumns['Номер']) ? $this->headerColumns['Номер'] : null;
        foreach ($this->rows as $i => $row) {
            $rowNumber = $i + 2;
            $dealId = trim((string) (isset($row[$techIndex]) ? $row[$techIndex] : ''));
            if ($dealId !== '') {
                if (isset($dealRows[$dealId])) {
                    throw new \RuntimeException("Дублируется Bitrix24 Deal ID {$dealId} в строках {$dealRows[$dealId]} и {$rowNumber}");
                }
                $dealRows[$dealId] = $rowNumber;
            }
            if ($orderColumn !== null) {
                $order = trim((string) (isset($row[$orderColumn - 1]) ? $row[$orderColumn - 1] : ''));
                if ($order !== '') {
                    $orderRows[$order][] = $rowNumber;
                }
            }
        }

        $nextRow = count($this->rows) + 2;
        $writes = [];
        $stats = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'cells' => 0];
        $requiredRows = 1;
        $sheet = Column::quoteSheet($this->sheetName);
        $incomingOrderCounts = [];
        foreach ($deals as $payload) {
            $result = $mapper->valueFor('Номер', $payload['deal'], $payload['client']);
            $order = trim((string) (isset($result['value']) ? $result['value'] : ''));
            if ($order !== '') {
                $incomingOrderCounts[$order] = (isset($incomingOrderCounts[$order]) ? $incomingOrderCounts[$order] : 0) + 1;
            }
        }

        foreach ($deals as $payload) {
            $deal = $payload['deal'];
            $client = $payload['client'];
            $dealId = (string) (isset($deal['id']) ? $deal['id'] : '');
            if ($dealId === '') {
                continue;
            }
            $rowNumber = isset($dealRows[$dealId]) ? $dealRows[$dealId] : null;
            if ($rowNumber === null) {
                $orderResult = $mapper->valueFor('Номер', $deal, $client);
                $order = trim((string) (isset($orderResult['value']) ? $orderResult['value'] : ''));
                if (
                    $order !== ''
                    && (isset($incomingOrderCounts[$order]) ? $incomingOrderCounts[$order] : 0) === 1
                    && isset($orderRows[$order])
                    && count($orderRows[$order]) === 1
                ) {
                    $rowNumber = $orderRows[$order][0];
                    unset($orderRows[$order]);
                    $this->logger->warning('Строка найдена по номеру заказа; в нее будет записан стабильный ID сделки', [
                        'deal_id' => $dealId,
                        'order' => $order,
                        'row' => $rowNumber,
                    ]);
                }
            }
            $isNew = $rowNumber === null;
            if ($isNew) {
                $rowNumber = $nextRow++;
            }
            $requiredRows = max($requiredRows, $rowNumber);

            $rowWritesBefore = count($writes);
            foreach ($this->headerColumns as $header => $columnNumber) {
                if ($header === $this->technicalHeader) {
                    continue;
                }
                $result = $mapper->valueFor($header, $deal, $client);
                if (!$result['managed']) {
                    continue;
                }
                $newValue = $result['value'];
                $oldValue = isset($this->rows[$rowNumber - 2][$columnNumber - 1]) ? $this->rows[$rowNumber - 2][$columnNumber - 1] : '';
                if (!$this->same($oldValue, $newValue)) {
                    $writes[] = [
                        'range' => $sheet . '!' . Column::letters($columnNumber) . $rowNumber,
                        'values' => [[$newValue]],
                    ];
                }
            }

            $oldDealId = isset($this->rows[$rowNumber - 2][$techIndex]) ? $this->rows[$rowNumber - 2][$techIndex] : '';
            if ((string) $oldDealId !== $dealId) {
                $writes[] = [
                    'range' => $sheet . '!' . Column::letters($this->technicalColumn) . $rowNumber,
                    'values' => [[$dealId]],
                ];
            }

            $rowChanges = count($writes) - $rowWritesBefore;
            if ($isNew) {
                $stats['created']++;
            } elseif ($rowChanges > 0) {
                $stats['updated']++;
            } else {
                $stats['unchanged']++;
            }
            $stats['cells'] += $rowChanges;
            $dealRows[$dealId] = $rowNumber;
        }

        return ['writes' => $writes, 'stats' => $stats, 'required_rows' => $requiredRows];
    }

    /**
     * Возвращает диапазоны строк, сделки которых отсутствуют в полном актуальном наборе.
     * Используется только в персональных таблицах менеджеров.
     */
    public function staleRowRanges(array $currentDealIds)
    {
        $current = array();
        foreach ($currentDealIds as $dealId) {
            $dealId = trim((string) $dealId);
            if ($dealId !== '') {
                $current[$dealId] = true;
            }
        }

        $lastColumn = max(count($this->headers), $this->technicalColumn, $this->autoHeaders === array() ? 0 : max(array_keys($this->autoHeaders)));
        $techIndex = $this->technicalColumn - 1;
        $sheet = Column::quoteSheet($this->sheetName);
        $ranges = array();
        foreach ($this->rows as $index => $row) {
            $dealId = trim((string) (isset($row[$techIndex]) ? $row[$techIndex] : ''));
            if ($dealId !== '' && !isset($current[$dealId])) {
                $rowNumber = $index + 2;
                $ranges[] = $sheet . '!A' . $rowNumber . ':' . Column::letters($lastColumn) . $rowNumber;
            }
        }
        return $ranges;
    }

    private function same($old, $new)
    {
        if (is_numeric($old) && is_numeric($new)) {
            return abs((float) $old - (float) $new) < 0.00000001;
        }
        return trim((string) $old) === trim((string) $new);
    }

    private function normalizeHeader($header)
    {
        $header = str_replace("\xC2\xA0", ' ', (string) $header);
        $header = preg_replace('/\s+/u', ' ', $header);
        return trim($header === null ? '' : $header);
    }
}
