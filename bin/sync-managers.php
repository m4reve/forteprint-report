#!/usr/bin/env php
<?php

use App\Bitrix24Client;
use App\Env;
use App\GoogleSheetsClient;
use App\HttpClient;
use App\Logger;
use App\StateStore;
use App\SyncConfig;
use App\SyncService;

$root = dirname(__DIR__);
foreach (glob($root . '/src/*.php') ?: array() as $file) {
    require_once $file;
}

$options = getopt('', array('env:', 'managers:', 'manager-id:', 'dry-run', 'diagnose', 'help'));
if (isset($options['help'])) {
    fwrite(STDOUT, "Использование: php bin/sync-managers.php [--env=.env] [--managers=config/managers.php] [--manager-id=123] [--dry-run] [--diagnose]\n");
    exit(0);
}

try {
    $envPath = isset($options['env']) ? $options['env'] : ($root . '/.env');
    if (substr((string) $envPath, 0, 1) !== '/') {
        $envPath = $root . '/' . $envPath;
    }
    Env::load((string) $envPath);
    date_default_timezone_set(Env::string('TIMEZONE', 'Europe/Moscow'));

    $resolve = function ($path) use ($root) {
        return substr((string) $path, 0, 1) === '/' ? $path : $root . '/' . $path;
    };
    $managersPath = isset($options['managers'])
        ? $resolve($options['managers'])
        : $resolve(Env::string('MANAGER_SPREADSHEETS_CONFIG', 'config/managers.php'));
    if (!is_file($managersPath)) {
        throw new RuntimeException("Не найден файл менеджеров {$managersPath}. Скопируйте config/managers.php.example в config/managers.php");
    }
    $managers = require $managersPath;
    if (!is_array($managers) || $managers === array()) {
        throw new RuntimeException('Файл менеджеров должен возвращать непустой массив');
    }

    $onlyManagerId = isset($options['manager-id']) ? (int) $options['manager-id'] : null;
    $logger = new Logger(Env::string('LOG_LEVEL', 'info'));
    $http = new HttpClient(Env::int('HTTP_TIMEOUT_SECONDS', 30));
    $bitrix = new Bitrix24Client(
        $http,
        Env::string('BITRIX24_WEBHOOK_BASE'),
        Env::nullableString('BITRIX24_PORTAL_URL')
    );
    $credentialsPath = $resolve(Env::string('GOOGLE_SERVICE_ACCOUNT_JSON', 'credentials/google-service-account.json'));
    $managerMappingPath = $resolve(Env::string('MANAGER_MAPPING_CONFIG', 'config/manager-mapping.php'));
    if (!is_file($managerMappingPath)) {
        throw new RuntimeException("Не найден файл сопоставления менеджерских таблиц {$managerMappingPath}");
    }
    $mappingTemplate = require $managerMappingPath;

    $lockPath = $resolve(Env::string('MANAGER_SYNC_LOCK_FILE', 'var/sync-managers.lock'));
    if (!is_dir(dirname($lockPath)) && !mkdir(dirname($lockPath), 0770, true) && !is_dir(dirname($lockPath))) {
        throw new RuntimeException('Не удалось создать каталог для lock-файла менеджеров');
    }
    $lock = fopen($lockPath, 'c+');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Другой процесс синхронизации менеджеров уже запущен');
    }

    $results = array();
    foreach ($managers as $managerId => $manager) {
        $managerId = (int) $managerId;
        if ($onlyManagerId !== null && $managerId !== $onlyManagerId) {
            continue;
        }
        if ($managerId <= 0 || !is_array($manager) || empty($manager['spreadsheet_id'])) {
            throw new RuntimeException('Для каждого менеджера нужны положительный ID и spreadsheet_id');
        }
        $managerName = isset($manager['name']) && trim((string) $manager['name']) !== ''
            ? (string) $manager['name']
            : ('Менеджер ' . $managerId);
        $mapping = $mappingTemplate;
        $config = SyncConfig::forManagers($mapping);
        $config['assigned_by_id'] = $managerId;

        $sheets = new GoogleSheetsClient(
            $http,
            $credentialsPath,
            (string) $manager['spreadsheet_id'],
            Env::int('GOOGLE_WRITE_REQUEST_INTERVAL_MS', 1200),
            $resolve(Env::string('GOOGLE_WRITE_RATE_FILE', 'var/google-write-rate.lock'))
        );
        $state = new StateStore($resolve('var/manager-' . $managerId . '-state.json'));
        $logger->info('Синхронизация персональной таблицы менеджера', array(
            'manager_id' => $managerId,
            'manager_name' => $managerName,
        ));
        $service = new SyncService($bitrix, $sheets, $state, $mapping, $config, $logger);
        // Персональные таблицы всегда строятся по полному актуальному набору,
        // чтобы удалить сделки, переназначенные другому менеджеру.
        $results[(string) $managerId] = array(
            'manager_name' => $managerName,
            'sync' => $service->run(true, isset($options['dry-run']), isset($options['diagnose'])),
        );
    }

    if ($onlyManagerId !== null && $results === array()) {
        throw new RuntimeException("Менеджер с ID {$onlyManagerId} отсутствует в конфигурации");
    }

    fwrite(STDOUT, json_encode(array(
        'status' => 'ok',
        'mode' => isset($options['diagnose']) ? 'diagnose' : (isset($options['dry-run']) ? 'dry-run' : 'full'),
        'managers' => $results,
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(0);
} catch (Exception $error) {
    fwrite(STDERR, json_encode(array(
        'status' => 'error',
        'message' => $error->getMessage(),
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
