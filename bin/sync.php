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
foreach (glob($root . '/src/*.php') ?: [] as $file) {
    require_once $file;
}

$options = getopt('', ['env:', 'full', 'dry-run', 'diagnose', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Использование: php bin/sync.php [--env=.env] [--full] [--dry-run] [--diagnose]\n");
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
        return substr($path, 0, 1) === '/' ? $path : $root . '/' . $path;
    };
    $logger = new Logger(Env::string('LOG_LEVEL', 'info'));
    $http = new HttpClient(Env::int('HTTP_TIMEOUT_SECONDS', 30));
    $bitrix = new Bitrix24Client(
        $http,
        Env::string('BITRIX24_WEBHOOK_BASE'),
        Env::nullableString('BITRIX24_PORTAL_URL')
    );
    $sheets = new GoogleSheetsClient(
        $http,
        $resolve(Env::string('GOOGLE_SERVICE_ACCOUNT_JSON', 'credentials/google-service-account.json')),
        Env::string('GOOGLE_SPREADSHEET_ID'),
        Env::int('GOOGLE_WRITE_REQUEST_INTERVAL_MS', 1200),
        $resolve(Env::string('GOOGLE_WRITE_RATE_FILE', 'var/google-write-rate.lock'))
    );
    $state = new StateStore($resolve(Env::string('SYNC_STATE_FILE', 'var/state.json')));
    $layoutMode = strtolower(Env::string('COMMON_LAYOUT_MODE', 'unified'));
    if ($layoutMode === 'legacy') {
        $mappingPath = $resolve(Env::string('LEGACY_COMMON_MAPPING_CONFIG', 'config/mapping.php'));
        if (!is_file($mappingPath)) {
            throw new RuntimeException("Не найден старый файл сопоставления {$mappingPath}");
        }
        $mapping = require $mappingPath;
        $config = SyncConfig::fromEnv($mapping);
        $forceFull = isset($options['full']);
    } elseif ($layoutMode === 'unified') {
        $mappingPath = $resolve(Env::string('COMMON_MAPPING_CONFIG', 'config/common-mapping.php'));
        if (!is_file($mappingPath)) {
            throw new RuntimeException("Не найден файл сопоставления общей таблицы {$mappingPath}");
        }
        $mapping = require $mappingPath;
        $config = SyncConfig::forCommon($mapping);
        // Как и персональные таблицы, общая таблица всегда строится по полному
        // актуальному набору, но без фильтра по ответственному менеджеру.
        $forceFull = true;
    } else {
        throw new RuntimeException('COMMON_LAYOUT_MODE должен быть unified или legacy');
    }

    $lockPath = $resolve(Env::string('SYNC_LOCK_FILE', 'var/sync.lock'));
    if (!is_dir(dirname($lockPath)) && !mkdir(dirname($lockPath), 0770, true) && !is_dir(dirname($lockPath))) {
        throw new RuntimeException('Не удалось создать каталог для lock-файла');
    }
    $lock = fopen($lockPath, 'c+');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Другой процесс синхронизации уже запущен');
    }

    $service = new SyncService($bitrix, $sheets, $state, $mapping, $config, $logger);
    $result = $service->run($forceFull, isset($options['dry-run']), isset($options['diagnose']));
    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(0);
} catch (Exception $error) {
    fwrite(STDERR, json_encode([
        'status' => 'error',
        'message' => $error->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
