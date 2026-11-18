<?php

require_once dirname(__DIR__) . '/src/Column.php';
require_once dirname(__DIR__) . '/src/HttpClient.php';
require_once dirname(__DIR__) . '/src/Bitrix24Client.php';
require_once dirname(__DIR__) . '/src/FieldMapper.php';
require_once dirname(__DIR__) . '/src/Env.php';
require_once dirname(__DIR__) . '/src/GoogleSheetsClient.php';
require_once dirname(__DIR__) . '/src/Logger.php';
require_once dirname(__DIR__) . '/src/SheetRepository.php';
require_once dirname(__DIR__) . '/src/MonthlySheet.php';
require_once dirname(__DIR__) . '/src/StateStore.php';
require_once dirname(__DIR__) . '/src/SyncConfig.php';
require_once dirname(__DIR__) . '/src/SyncService.php';

use App\Bitrix24Client;
use App\Column;
use App\FieldMapper;
use App\HttpClient;
use App\Env;
use App\GoogleSheetsClient;
use App\Logger;
use App\MonthlySheet;
use App\SheetRepository;
use App\StateStore;
use App\SyncConfig;
use App\SyncService;

class FakeHttpClient extends HttpClient
{
    public $requests = array();

    public function requestJson($method, $url, array $headers = array(), $body = null)
    {
        $this->requests[] = array('method' => $method, 'url' => $url, 'body' => $body);
        if (strpos($url, 'crm.item.list.json') !== false) {
            return array('result' => array('items' => array(array(
                'id' => 42,
                'createdTime' => '2026-09-25T12:00:00+03:00',
                'assignedById' => 99,
            ))));
        }
        if (strpos($url, 'crm.stagehistory.list.json') !== false) {
            return array('result' => array('items' => array(
                array('ID' => 1, 'OWNER_ID' => 42, 'STAGE_ID' => 'PREPAYMENT_INVOICE', 'CREATED_TIME' => '2026-09-10T08:15:00+03:00'),
                array('ID' => 2, 'OWNER_ID' => 42, 'STAGE_ID' => 'C7:WON', 'CREATED_TIME' => '2026-09-25T12:00:00+03:00'),
            )));
        }
        return array('result' => array());
    }
}

class FakeGoogleSheetsClient extends GoogleSheetsClient
{
    public $writes = array();
    public $batchUpdateCalls = 0;
    private $headers;
    private $rows;

    public function __construct(array $headers = array('Номер'), array $rows = array())
    {
        $this->headers = $headers;
        $this->rows = $rows;
    }

    public function getValues($range)
    {
        if (strpos($range, 'A1:AZ1') !== false) {
            return array($this->headers);
        }
        return $this->rows;
    }

    public function batchUpdate(array $data, $valueInputOption = 'RAW')
    {
        $this->batchUpdateCalls++;
        foreach ($data as $write) {
            $write['_input_option'] = $valueInputOption;
            $this->writes[] = $write;
        }
        return count($data);
    }

    public function listSheetNames()
    {
        return array();
    }

    public function sheetExists($sheetName)
    {
        return false;
    }
}

$bitrix = new Bitrix24Client(new HttpClient(), 'https://invalid.example/rest/1/token');
$mapper = new FieldMapper(
    [
        'createdTime' => ['title' => 'Created at', 'type' => 'datetime'],
        'ufCrmOrder' => ['title' => 'Номер заказа', 'type' => 'string'],
        'ufCrmDeadline' => ['title' => 'Срок выполнения заказа', 'type' => 'date'],
        'ufCrmProof' => [
            'title' => 'Наличие цветопробы',
            'type' => 'enumeration',
            'items' => [['ID' => '7', 'VALUE' => 'Есть']],
        ],
        'ufCrmMaterial' => ['title' => 'Материал заказа', 'type' => 'string'],
        'ufCrmTechnology' => ['title' => 'Технология', 'type' => 'string'],
        'ufCrmArea' => ['title' => 'Площадь работы, м2', 'type' => 'double'],
        'ufCrm1790575342' => [
            'title' => 'Тип сделки',
            'type' => 'enumeration',
            'items' => [['ID' => '91', 'VALUE' => 'Наружная реклама']],
        ],
    ],
    [
        'columns' => [
            'Отметка времени' => ['type' => 'field', 'field' => 'createdTime', 'format' => 'datetime'],
            'Дата принт' => ['type' => 'custom', 'title' => 'Срок выполнения заказа', 'format' => 'date_text'],
            'Дата создания сделки в Б24' => ['type' => 'field', 'field' => 'createdTime', 'format' => 'date_text'],
            'Дата перехода сделки в Успешно реализовано' => ['type' => 'computed', 'name' => 'won_date'],
            'Дата выставлен счет' => ['type' => 'computed', 'name' => 'invoice_date'],
            // Код должен иметь приоритет: title намеренно указывает на другое поле.
            'Тип сделки' => ['type' => 'custom', 'field' => 'UF_CRM_1790575342', 'title' => 'Номер заказа'],
            'Некорректный код' => ['type' => 'custom', 'field' => 'UF_CRM_DOES_NOT_EXIST', 'title' => 'Номер заказа'],
            'Номер заказа' => ['type' => 'custom', 'title' => 'Номер заказа'],
            'Наличие цветопробы' => ['type' => 'custom', 'title' => 'Наличие цветопробы'],
            'ТЗ ( материал, технология, квадратура)' => ['type' => 'computed', 'name' => 'job_spec'],
            'Ссылка на сделку в Битрикс24' => ['type' => 'computed', 'name' => 'deal_url'],
        ],
        'job_spec_parts' => [
            ['field' => 'UF_CRM_MATERIAL', 'label' => 'Материал'],
            ['title' => 'Технология', 'label' => 'Технология'],
            ['title' => 'Площадь работы, м2', 'label' => 'Площадь', 'suffix' => ' м²'],
        ],
    ],
    $bitrix,
    [],
    []
);
$testDeal = [
    'createdTime' => '2026-09-25T12:00:00+03:00',
    'stageId' => 'WON',
    '_wonStageDate' => '2026-09-25T12:00:00+03:00',
    '_invoiceStageDate' => '2026-09-10T08:15:00+03:00',
    'ufCrm1790575342' => '91',
    'ufCrmOrder' => 'A-15',
    'ufCrmDeadline' => '2026-10-01T17:30:00+03:00',
    'ufCrmProof' => '7',
    'ufCrmMaterial' => 'Баннер',
    'ufCrmTechnology' => 'УФ-печать',
    'ufCrmArea' => 12.5,
];
$unresolvedMapperFields = $mapper->unresolvedCustomFields();

$fakeHttp = new FakeHttpClient();
$fakeBitrix = new Bitrix24Client($fakeHttp, 'https://example.bitrix24.ru/rest/1/token');
$stageIds = array('C7:PAID', 'C7:PRODUCTION', 'C7:READY', 'C7:DOCUMENTS', 'C7:WON');
$fakeBitrix->listDeals(array('ufCrm1790575342'), null, 7, $stageIds);
$lastRequest = $fakeHttp->requests[count($fakeHttp->requests) - 1];
$fakeBitrix->listDeals(array(), null, 7, $stageIds, 99);
$managerDealRequest = $fakeHttp->requests[count($fakeHttp->requests) - 1];
$stageDates = $fakeBitrix->stageEnteredDates(array(42), array('PREPAYMENT_INVOICE', 'C7:WON'));
$stageHistoryRequest = $fakeHttp->requests[count($fakeHttp->requests) - 1];

$sheetClient = new FakeGoogleSheetsClient();
$autoHeaders = array(
    13 => 'Ссылка на сделку в Битрикс24',
    14 => 'Дата создания сделки в Б24',
    15 => 'Дата перехода сделки в Успешно реализовано',
    16 => 'Тип сделки',
    17 => 'Дата выставлен счет',
);
$repository = new SheetRepository(
    $sheetClient,
    'Лист1',
    'Bitrix24 Deal ID',
    12,
    new Logger('error'),
    $autoHeaders
);
$repository->load(true);
$linkMapper = new FieldMapper(
    array('ufCrmOrder' => array('title' => 'Номер заказа', 'type' => 'string')),
    array('columns' => array(
        'Номер' => array('type' => 'custom', 'title' => 'Номер заказа'),
        'Ссылка на сделку в Битрикс24' => array('type' => 'computed', 'name' => 'deal_url'),
    )),
    $fakeBitrix,
    array(),
    array()
);
$sheetPlan = $repository->planWrites(array(array(
    'deal' => array('id' => 42, 'ufCrmOrder' => 'A-15'),
    'client' => array(),
)), $linkMapper);
$plannedRanges = array();
foreach ($sheetPlan['writes'] as $write) {
    $plannedRanges[$write['range']] = $write['values'][0][0];
}

$existingHeaders = array(
    'Номер', '', '', '', '', '', '', '', '', '', '', 'Bitrix24 Deal ID',
    'Ссылка на сделку в Битрикс24', 'Дата создания сделки в Б24',
    'Дата перехода сделки в Успешно реализовано', 'Тип сделки',
    'Дата выставлен счет',
);
$existingRow = array(
    'A-15', '', '', '', '', '', '', '', '', '', '', '42',
    'https://old.example/crm/deal/details/42/', '', '', '', '',
);
$existingSheetClient = new FakeGoogleSheetsClient($existingHeaders, array($existingRow));
$existingRepository = new SheetRepository(
    $existingSheetClient,
    'Лист1',
    'Bitrix24 Deal ID',
    12,
    new Logger('error'),
    $autoHeaders
);
$existingRepository->load(true);
$existingPlan = $existingRepository->planWrites(array(array(
    'deal' => array('id' => 42, 'ufCrmOrder' => 'A-15'),
    'client' => array(),
)), $linkMapper);
$staleRanges = $existingRepository->staleRowRanges(array());

$managerMapping = require dirname(__DIR__) . '/config/manager-mapping.php';
$commonMapping = require dirname(__DIR__) . '/config/common-mapping.php';
$commonConfigMapping = $commonMapping;
putenv('COMMON_TECHNICAL_ID_COLUMN=0');
putenv('COMMON_REBUILD_LAYOUT=0');
$commonConfig = SyncConfig::forCommon($commonConfigMapping);
$toUniversalFieldCode = function ($code) {
    $code = (string) $code;
    if (preg_match('/^UF_CRM_(.+)$/i', $code, $matches)) {
        $parts = array_values(array_filter(explode('_', strtolower($matches[1])), 'strlen'));
        $suffix = '';
        foreach ($parts as $part) {
            $suffix .= ctype_digit($part) ? $part : ucfirst($part);
        }
        return 'ufCrm' . $suffix;
    }
    return $code;
};
$managerCodes = array(
    'order' => $toUniversalFieldCode($managerMapping['columns']['№']['field']),
    'payment' => $toUniversalFieldCode($managerMapping['columns']['Оплата']['field']),
    'cost' => $toUniversalFieldCode($managerMapping['columns']['Себес']['field']),
    'material' => $toUniversalFieldCode($managerMapping['columns']['Материал']['field']),
    'area' => $toUniversalFieldCode($managerMapping['columns']['Квадратура']['field']),
    'extra' => $toUniversalFieldCode($managerMapping['columns']['Доп траты']['field']),
    'recipient' => $toUniversalFieldCode($managerMapping['columns']['Кому']['field']),
);
$managerMapper = new FieldMapper(
    array(
        $managerCodes['order'] => array('title' => 'Номер заказа', 'type' => 'string'),
        $managerCodes['payment'] => array('title' => 'Оплата', 'type' => 'string'),
        $managerCodes['cost'] => array('title' => 'Себестоимость производства', 'type' => 'double'),
        $managerCodes['material'] => array('title' => 'Материал заказа', 'type' => 'string'),
        $managerCodes['area'] => array('title' => 'Площадь работы, м2', 'type' => 'double'),
        $managerCodes['extra'] => array('title' => 'Доп траты', 'type' => 'double'),
        $managerCodes['recipient'] => array('title' => 'Кому', 'type' => 'string'),
    ),
    $managerMapping,
    $fakeBitrix,
    array(),
    array()
);
$managerHeaders = array_keys($managerMapping['columns']);
$managerHeaders[] = 'Bitrix24 Deal ID';
$managerTechnicalColumn = count($managerMapping['columns']) + 1;
$managerSheetClient = new FakeGoogleSheetsClient($managerHeaders, array());
$managerLayout = array();
foreach (array_keys($managerMapping['columns']) as $index => $header) {
    $managerLayout[$index + 1] = $header;
}
$managerRepository = new SheetRepository(
    $managerSheetClient,
    'Сентябрь 2026',
    'Bitrix24 Deal ID',
    $managerTechnicalColumn,
    new Logger('error'),
    $managerLayout,
    '№'
);
$managerRepository->load(true);
$managerPlan = $managerRepository->planWrites(array(array(
    'deal' => array(
        'id' => 42,
        'contactIds' => array(77),
        'comments' => 'Комментарий менеджера',
        $managerCodes['order'] => 'A-15',
        '_invoiceStageDate' => '2026-09-10T08:15:00+03:00',
        'opportunity' => 10000,
        $managerCodes['payment'] => 'Касса',
        $managerCodes['cost'] => 4000,
        $managerCodes['material'] => 'Баннер',
        $managerCodes['area'] => 12.5,
        $managerCodes['extra'] => 2000,
        $managerCodes['recipient'] => 'Производство',
    ),
    'client' => array('name' => 'Анна'),
)), $managerMapper);
$managerRawRanges = array();
foreach ($managerPlan['writes'] as $write) {
    $managerRawRanges[$write['range']] = $write['values'][0][0];
}
$managerFormulaRanges = array();
foreach ($managerPlan['formula_writes'] as $write) {
    $managerFormulaRanges[$write['range']] = $write['values'][0][0];
}
$managerExtraFormulaColumn = array_search('Доп траты 1,5х', array_keys($managerMapping['columns']), true) + 1;
$managerDeltaColumn = array_search('Дельта', array_keys($managerMapping['columns']), true) + 1;
$managerContactLinkColumn = array_search('Ссылка на контакт сделки', array_keys($managerMapping['columns']), true) + 1;
$managerDealLinkColumn = array_search('Ссылка на сделку', array_keys($managerMapping['columns']), true) + 1;
$managerCommentColumn = array_search('Комментарий', array_keys($managerMapping['columns']), true) + 1;
$managerTechnicalLetter = Column::letters($managerTechnicalColumn);

$monthlyGoogle = new FakeGoogleSheetsClient();
$monthlyState = new StateStore(sys_get_temp_dir() . '/bitrix24-google-sheets-sync-test-state.json');
$monthlyMapping = array('columns' => array(
    'Номер' => array('type' => 'field', 'field' => 'id'),
));
$monthlyConfig = array(
    'technical_header' => 'Bitrix24 Deal ID',
    'technical_column' => 2,
    'lookback_seconds' => 300,
    'category_id' => 7,
    'stage_ids' => $stageIds,
    'invoice_stage_id' => 'PREPAYMENT_INVOICE',
    'won_stage_id' => 'C7:WON',
    'auto_headers' => array(),
    'layout_headers' => array(1 => 'Номер'),
    'month_source_field' => 'createdTime',
    'assigned_by_id' => null,
    'remove_stale_rows' => false,
);
$monthlyService = new SyncService($fakeBitrix, $monthlyGoogle, $monthlyState, $monthlyMapping, $monthlyConfig, new Logger('error'));
$monthlyResult = $monthlyService->run(true, true, false);

$tests = [
    'column A' => Column::letters(1) === 'A',
    'column Z' => Column::letters(26) === 'Z',
    'column AA' => Column::letters(27) === 'AA',
    'column AZ' => Column::letters(52) === 'AZ',
    'sheet quoting' => Column::quoteSheet("Заказы '2026'") === "'Заказы ''2026'''",
    'month key from deal creation date' => MonthlySheet::keyForDeal($testDeal, 'createdTime') === '2026-09',
    'russian monthly sheet name' => MonthlySheet::nameForKey('2026-09') === 'Сентябрь 2026',
    'monthly sheet name detection' => MonthlySheet::isMonthlyName('Октябрь 2026') && !MonthlySheet::isMonthlyName('Лист1'),
    'monthly sheet chronological sort key' => MonthlySheet::sortKeyForName('Август 2026') === '2026-08',
    'date is a native serial' => abs((float) $mapper->valueFor('Отметка времени', $testDeal, [])['value'] - 46290.5) < 0.00000001,
    'print date is mapped as date-only text' => $mapper->valueFor('Дата принт', $testDeal, [])['value'] === '01.10.2026',
    'deal created date is mapped as date-only text' => $mapper->valueFor('Дата создания сделки в Б24', $testDeal, [])['value'] === '25.09.2026',
    'won transition date is mapped from history as date-only text' => $mapper->valueFor('Дата перехода сделки в Успешно реализовано', $testDeal, [])['value'] === '25.09.2026',
    'invoice transition date is mapped from history as date-only text' => $mapper->valueFor('Дата выставлен счет', $testDeal, [])['value'] === '10.09.2026',
    'custom deal type label' => $mapper->valueFor('Тип сделки', $testDeal, [])['value'] === 'Наружная реклама',
    'symbolic code has priority over title' => $mapper->valueFor('Тип сделки', $testDeal, [])['value'] === 'Наружная реклама',
    'invalid explicit symbolic code does not fall back to title' => !$mapper->valueFor('Некорректный код', $testDeal, [])['managed'] && isset($unresolvedMapperFields['Некорректный код']),
    'custom field by title' => $mapper->valueFor('Номер заказа', $testDeal, [])['value'] === 'A-15',
    'enum label' => $mapper->valueFor('Наличие цветопробы', $testDeal, [])['value'] === 'Есть',
    'combined job spec' => $mapper->valueFor('ТЗ ( материал, технология, квадратура)', $testDeal, [])['value'] === 'Материал: Баннер; Технология: УФ-печать; Площадь: 12.5 м²',
    'deal url' => $mapper->valueFor('Ссылка на сделку в Битрикс24', $testDeal + ['id' => 42], [])['value'] === 'https://invalid.example/crm/deal/details/42/',
    'deal list filters all stages' => isset($lastRequest['body']['filter']['@stageId']) && $lastRequest['body']['filter']['@stageId'] === $stageIds,
    'deal list filters selected funnel' => isset($lastRequest['body']['filter']['categoryId']) && $lastRequest['body']['filter']['categoryId'] === 7,
    'deal list filters assigned manager' => isset($managerDealRequest['body']['filter']['assignedById']) && $managerDealRequest['body']['filter']['assignedById'] === 99,
    'deal list selects lifecycle and custom type fields' => in_array('createdTime', $lastRequest['body']['select'], true) && in_array('ufCrm1790575342', $lastRequest['body']['select'], true),
    'deal list selects standard comments field' => in_array('comments', $lastRequest['body']['select'], true),
    'stage history dates are loaded' => isset($stageDates['PREPAYMENT_INVOICE']['42']) && $stageDates['PREPAYMENT_INVOICE']['42'] === '2026-09-10T08:15:00+03:00' && isset($stageDates['C7:WON']['42']),
    'stage history filters deal and stages' => isset($stageHistoryRequest['body']['filter']['@OWNER_ID']) && $stageHistoryRequest['body']['filter']['@OWNER_ID'] === array(42) && isset($stageHistoryRequest['body']['filter']['@STAGE_ID']) && $stageHistoryRequest['body']['filter']['@STAGE_ID'] === array('PREPAYMENT_INVOICE', 'C7:WON'),
    'custom deal type field is requested by mapper' => in_array('ufCrm1790575342', $mapper->requiredCustomFieldCodes(), true),
    'technical header is created' => isset($sheetClient->writes[0]['range']) && $sheetClient->writes[0]['range'] === "'Лист1'!L1",
    'deal link header is created' => isset($sheetClient->writes[1]['range']) && $sheetClient->writes[1]['range'] === "'Лист1'!M1",
    'deal created header is created' => isset($sheetClient->writes[2]['range']) && $sheetClient->writes[2]['range'] === "'Лист1'!N1",
    'won date header is created' => isset($sheetClient->writes[3]['range']) && $sheetClient->writes[3]['range'] === "'Лист1'!O1",
    'deal type header is created' => isset($sheetClient->writes[4]['range']) && $sheetClient->writes[4]['range'] === "'Лист1'!P1",
    'invoice date header is created' => isset($sheetClient->writes[5]['range']) && $sheetClient->writes[5]['range'] === "'Лист1'!Q1",
    'all missing headers use one batch write request' => $sheetClient->batchUpdateCalls === 1,
    'new row gets deal link' => isset($plannedRanges["'Лист1'!M2"]) && $plannedRanges["'Лист1'!M2"] === 'https://example.bitrix24.ru/crm/deal/details/42/',
    'new row gets stable deal id' => isset($plannedRanges["'Лист1'!L2"]) && (string) $plannedRanges["'Лист1'!L2"] === '42',
    'newly filled cells are not highlighted' => $sheetPlan['highlight_cells'] === array(),
    'existing row is supplemented instead of duplicated' => $existingPlan['stats']['created'] === 0 && $existingPlan['stats']['updated'] === 1 && count($existingPlan['writes']) === 1 && $existingPlan['writes'][0]['range'] === "'Лист1'!M2",
    'changed non-empty existing cell is highlighted' => $existingPlan['highlight_cells'] === array(array('row' => 2, 'column' => 13)),
    'write plan reports required row capacity' => $sheetPlan['required_rows'] === 2,
    'stale manager row is selected for clearing' => $staleRanges === array("'Лист1'!A2:Q2"),
    'manager extra costs use locale decimal comma in formula' => isset($managerFormulaRanges["'Сентябрь 2026'!" . Column::letters($managerExtraFormulaColumn) . '2']) && $managerFormulaRanges["'Сентябрь 2026'!" . Column::letters($managerExtraFormulaColumn) . '2'] === '=2000*1,5',
    'manager delta uses row formula' => isset($managerFormulaRanges["'Сентябрь 2026'!" . Column::letters($managerDeltaColumn) . '2']) && $managerFormulaRanges["'Сентябрь 2026'!" . Column::letters($managerDeltaColumn) . '2'] === '=D2-F2-I2',
    'manager layout ends with contact deal and comment fields' => array_slice(array_keys($managerMapping['columns']), -3) === array('Ссылка на контакт сделки', 'Ссылка на сделку', 'Комментарий'),
    'common table uses exactly manager field mapping' => $commonMapping === $managerMapping,
    'common table includes deals of all managers' => $commonConfig['assigned_by_id'] === null,
    'common table uses full stale-row cleanup logic' => $commonConfig['remove_stale_rows'] === true,
    'common technical id follows all visible fields' => $commonConfig['technical_column'] === count($commonMapping['columns']) + 1,
    'legacy common mapping remains available' => is_file(dirname(__DIR__) . '/config/mapping.php'),
    'manager contact link points to first deal contact' => isset($managerRawRanges["'Сентябрь 2026'!" . Column::letters($managerContactLinkColumn) . '2']) && $managerRawRanges["'Сентябрь 2026'!" . Column::letters($managerContactLinkColumn) . '2'] === 'https://example.bitrix24.ru/crm/contact/details/77/',
    'manager deal link points to deal card' => isset($managerRawRanges["'Сентябрь 2026'!" . Column::letters($managerDealLinkColumn) . '2']) && $managerRawRanges["'Сентябрь 2026'!" . Column::letters($managerDealLinkColumn) . '2'] === 'https://example.bitrix24.ru/crm/deal/details/42/',
    'manager comment is exported from standard deal field' => isset($managerRawRanges["'Сентябрь 2026'!" . Column::letters($managerCommentColumn) . '2']) && $managerRawRanges["'Сентябрь 2026'!" . Column::letters($managerCommentColumn) . '2'] === 'Комментарий менеджера',
    'manager layout keeps technical deal id after visible columns' => isset($managerRawRanges["'Сентябрь 2026'!{$managerTechnicalLetter}2"]) && (string) $managerRawRanges["'Сентябрь 2026'!{$managerTechnicalLetter}2"] === '42',
    'sync groups a September deal into September sheet' => isset($monthlyResult['sheets']['Сентябрь 2026']['would_create_sheet']) && $monthlyResult['sheets']['Сентябрь 2026']['deals'] === 1,
];

$failed = 0;
foreach ($tests as $name => $ok) {
    fwrite($ok ? STDOUT : STDERR, ($ok ? 'OK ' : 'FAIL ') . $name . PHP_EOL);
    $failed += $ok ? 0 : 1;
}
exit($failed === 0 ? 0 : 1);
