# Установка синхронизации Битрикс24 → Google Sheets

Скрипт запускается из командной строки PHP и по cron. Composer и база данных не нужны. Новые сделки добавляются в Google Sheets, существующие строки обновляются по служебному полю `Bitrix24 Deal ID`.

## 1. Требования

- Linux-сервер с доступом в интернет по HTTPS к `*.bitrix24.ru`, `oauth2.googleapis.com` и `sheets.googleapis.com`.
- PHP CLI версии 5.4 или новее.
- Расширения PHP: `curl`, `json`, `mbstring`, `openssl`.
- Входящий вебхук Битрикс24 с правами чтения CRM и пользователей.
- Проект Google Cloud, Google Sheets API и сервисный аккаунт с JSON-ключом.
- Существующая Google-таблица.

Узнайте, какой PHP будет использовать cron:

```bash
command -v php
php -v
```

Проверьте расширения именно в CLI-PHP:

```bash
php -r 'foreach(array("curl","json","mbstring","openssl") as $e){echo $e.": ".(extension_loaded($e)?"OK":"MISSING").PHP_EOL;}'
```

Все четыре строки должны оканчиваться на `OK`. Если `curl` показывает `MISSING`, установите или включите модуль cURL для той версии PHP, которую выводит `php -v`. После этого повторите проверку. Ошибка `Call to undefined function App\curl_init()` означает, что cURL не подключен именно к CLI-PHP.

## 2. Размещение файлов

Скопируйте весь каталог проекта на сервер. Например:

```text
/home/bitrix/www/local/php_interface/classes/bitrix24-google-sheets-sync
```

Либо используйте отдельный каталог:

```text
/opt/bitrix24-google-sheets-sync
```

Внутри должны находиться как минимум:

```text
bin/sync.php
config/mapping.php
src/
tests/run.php
.env.example
cron.example
```

Перейдите в каталог проекта и создайте рабочие директории:

```bash
cd /home/bitrix/www/local/php_interface/classes/bitrix24-google-sheets-sync
mkdir -p credentials var
cp .env.example .env
chmod 700 credentials
chmod 600 .env
```

Каталог проекта, `.env`, `credentials` и `var` должны быть доступны на чтение и запись системному пользователю, от которого будет работать cron. На BitrixVM это часто пользователь `bitrix`.

## 3. Входящий вебхук Битрикс24

1. В Битрикс24 откройте раздел **Разработчикам → Другое → Входящий вебхук**.
2. Создайте вебхук с правами чтения CRM.
3. Для получения имени ответственного предоставьте также доступ к пользователям.
4. Скопируйте URL вида:

   ```text
   https://example.bitrix24.ru/rest/1/секретный_токен
   ```

5. URL должен быть указан без имени REST-метода в конце.

В `.env` заполните:

```env
BITRIX24_WEBHOOK_BASE=https://example.bitrix24.ru/rest/1/секретный_токен
BITRIX24_PORTAL_URL=https://example.bitrix24.ru
```

URL вебхука является секретом. Не отправляйте его в переписке и не добавляйте `.env` в Git.

## 4. Воронка и стадии Битрикс24

Укажите ID воронки и реальные `STAGE_ID` стадий, сделки из которых должны попадать в таблицу:

```env
BITRIX24_CATEGORY_ID=0
BITRIX24_STAGE_IDS=PREPAYMENT_INVOICE,UC_RYXEZ2,EXECUTING,FINAL_INVOICE,UC_G4P48R,WON
```

Для основной воронки используется `BITRIX24_CATEGORY_ID=0`. У дополнительной воронки стадии обычно имеют вид `C7:UC_ABC123`, а успешная стадия — `C7:WON`.

Для дат перехода по истории используются настройки:

```env
BITRIX24_INVOICE_STAGE_ID=PREPAYMENT_INVOICE
BITRIX24_WON_STAGE_ID=
```

Если `BITRIX24_WON_STAGE_ID` пустой, скрипт сам найдет `WON` или `C…:WON` в `BITRIX24_STAGE_IDS`. Для дополнительной воронки можно указать значение явно, например `BITRIX24_WON_STAGE_ID=C7:WON`.

## 5. Настройка Google Cloud

1. Откройте Google Cloud Console и создайте или выберите проект.
2. В разделе API включите **Google Sheets API**.
3. Откройте **IAM и администрирование → Сервисные аккаунты**.
4. Создайте сервисный аккаунт.
5. Создайте для него ключ типа JSON и скачайте файл.
6. Поместите файл на сервер под именем:

   ```text
   credentials/google-service-account.json
   ```

7. Ограничьте доступ к ключу:

   ```bash
   chmod 600 credentials/google-service-account.json
   ```

8. Откройте JSON-файл и найдите значение `client_email`.
9. Откройте нужную Google-таблицу и предоставьте этому адресу доступ **Редактор**.
10. Возьмите ID таблицы из URL:

    ```text
    https://docs.google.com/spreadsheets/d/ID_ТАБЛИЦЫ/edit
    ```

В `.env` заполните:

```env
GOOGLE_SPREADSHEET_ID=ID_ТАБЛИЦЫ
GOOGLE_SHEET_NAME=Лист1
GOOGLE_SERVICE_ACCOUNT_JSON=credentials/google-service-account.json
MONTH_SOURCE_FIELD=createdTime
```

`GOOGLE_SHEET_NAME` сохранен для обратной совместимости со старой однолистовой версией. Актуальная версия сама создает вкладки с названиями `Август 2026`, `Сентябрь 2026` и т. д. по полю `MONTH_SOURCE_FIELD`.

## 6. Заголовки Google Sheets

На новых помесячных листах скрипт сам создает рабочие заголовки из `config/mapping.php`:

1. `Номер`
2. `Дата принт`
3. `Клиент`
4. `Как оплачивал`
5. `Сумма заказа`
6. `ТЗ ( материал, технология, квадратура)`
7. `Макет/ закупка/ доставка х1.5`
8. `Менеджер`
9. `Сумма факт`
10. `Дельта`
11. `комментарий если надо`

Также автоматически создаются служебные и дополнительные столбцы:

- `L` — `Bitrix24 Deal ID`;
- `M` — `Ссылка на сделку в Битрикс24`;
- `N` — `Дата создания сделки в Б24`;
- `O` — `Дата перехода сделки в Успешно реализовано`;
- `P` — `Тип сделки`;
- `Q` — `Дата выставлен счет`.

Если вы заранее создали месячный лист вручную и `L:Q` уже заняты другими данными, укажите свободные номера столбцов в `.env`. Скрипт не перезаписывает занятые заголовки.

## 7. Основные настройки `.env`

Минимальный пример:

```env
BITRIX24_WEBHOOK_BASE=https://example.bitrix24.ru/rest/1/секретный_токен
BITRIX24_PORTAL_URL=https://example.bitrix24.ru
BITRIX24_CATEGORY_ID=0
BITRIX24_STAGE_IDS=PREPAYMENT_INVOICE,UC_RYXEZ2,EXECUTING,FINAL_INVOICE,UC_G4P48R,WON

GOOGLE_SPREADSHEET_ID=ID_ТАБЛИЦЫ
GOOGLE_SHEET_NAME=Лист1
GOOGLE_SERVICE_ACCOUNT_JSON=credentials/google-service-account.json
MONTH_SOURCE_FIELD=createdTime
MANAGER_SPREADSHEETS_CONFIG=config/managers.php
MANAGER_SYNC_LOCK_FILE=var/sync-managers.lock

TECHNICAL_ID_COLUMN=12
TECHNICAL_ID_HEADER=Bitrix24 Deal ID
DEAL_LINK_COLUMN=13
DEAL_LINK_HEADER=Ссылка на сделку в Битрикс24
DEAL_CREATED_COLUMN=14
DEAL_CREATED_HEADER=Дата создания сделки в Б24
DEAL_WON_DATE_COLUMN=15
DEAL_WON_DATE_HEADER=Дата перехода сделки в Успешно реализовано
DEAL_TYPE_COLUMN=16
DEAL_TYPE_HEADER=Тип сделки
INVOICE_DATE_COLUMN=17
INVOICE_DATE_HEADER=Дата выставлен счет

BITRIX24_INVOICE_STAGE_ID=PREPAYMENT_INVOICE
BITRIX24_WON_STAGE_ID=

TIMEZONE=Europe/Moscow
SYNC_LOOKBACK_SECONDS=300
SYNC_STATE_FILE=var/state.json
SYNC_LOCK_FILE=var/sync.lock
HTTP_TIMEOUT_SECONDS=30
LOG_LEVEL=info
```

Пользовательское поле «Тип сделки» читается по коду `UF_CRM_1790575342`. В универсальном REST API оно называется `ufCrm1790575342`.

## 8. Проверка установки

Проверьте синтаксис и встроенные тесты:

```bash
find src bin tests config -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/run.php
```

Все строки проверки синтаксиса должны содержать `No syntax errors detected`, а тесты — `OK`.

Запустите диагностику подключений:

```bash
php bin/sync.php --diagnose
```

Диагностика читает данные из обоих API, но ничего не записывает. Результат должен содержать:

```json
{
    "status": "ok",
    "mode": "diagnose"
}
```

Проверьте полный план без записи:

```bash
php bin/sync.php --full --dry-run
```

## 9. Первый запуск

Для первой загрузки всех подходящих сделок выполните:

```bash
php bin/sync.php --full
```

Сделки будут распределены по автоматически созданным листам `Август 2026`, `Сентябрь 2026` и т. д. Месяц определяется настройкой `MONTH_SOURCE_FIELD`, по умолчанию — `createdTime`. После обновления старой однолистовой версии также обязательно выполните полный запуск: данные на прежнем `Лист1` автоматически не удаляются.

Именно рабочий запуск без `--dry-run` создаст отсутствующие служебные столбцы. После завершения проверьте несколько сделок вручную в Битрикс24 и Google Sheets.

Повторный обычный запуск обрабатывает только сделки, измененные после последней успешной синхронизации:

```bash
php bin/sync.php
```

После изменения правил сопоставления или добавления новых столбцов один раз запускайте `php bin/sync.php --full`, чтобы обновить старые строки.

## 10. Настройка cron

Узнайте абсолютный путь к PHP:

```bash
command -v php
```

Откройте crontab того пользователя, которому доступны `.env`, JSON-ключ и каталог `var`:

```bash
crontab -e
```

Добавьте запуск каждые пять минут, заменив пути на фактические:

```cron
*/5 * * * * cd /home/bitrix/www/local/php_interface/classes/bitrix24-google-sheets-sync && /usr/bin/php bin/sync.php >> var/cron.log 2>&1
```

Проверьте журнал после ближайшего запуска:

```bash
tail -n 100 var/cron.log
```

Lock-файл не позволяет двум копиям скрипта выполняться одновременно. В `var/state.json` хранится время последней успешной синхронизации.

### Отдельные таблицы менеджеров

Создайте конфигурацию:

```bash
cp config/managers.php.example config/managers.php
```

В `config/managers.php` укажите ID пользователей Битрикс24 и ID их Google-таблиц. Каждую таблицу откройте для `client_email` сервисного аккаунта. Затем проверьте и запустите:

```bash
php bin/sync-managers.php --dry-run
php bin/sync-managers.php
```

Cron персональных таблиц:

```cron
*/15 * * * * cd /home/bitrix/www/local/php_interface/classes/bitrix24-google-sheets-sync && /usr/bin/php bin/sync-managers.php >> var/cron-managers.log 2>&1
```

Персональная синхронизация всегда полная. Это позволяет удалить из старой таблицы сделку после смены ответственного менеджера.

## 11. Частые ошибки

### `Call to undefined function App\curl_init()`

В CLI-PHP не подключено расширение cURL. Проверьте `php -v`, `php --ini` и `php -m`. Включите модуль cURL именно для используемой cron версии PHP.

### `Connection timed out`

Сервер не может установить HTTPS-соединение с Bitrix24 или Google. Проверьте DNS, исходящие подключения на порт 443, firewall и proxy. При медленном соединении можно увеличить:

```env
HTTP_TIMEOUT_SECONDS=60
```

### Google возвращает `403` или `PERMISSION_DENIED`

Проверьте, что Google Sheets API включен и таблица открыта с правами редактора для `client_email` из JSON-ключа.

### `Не найден JSON-ключ Google`

Проверьте путь `GOOGLE_SERVICE_ACCOUNT_JSON`, права на файл и рабочий каталог команды cron.

### `invalid_grant` при авторизации Google

Проверьте системную дату и время сервера, JSON-ключ и статус сервисного аккаунта. Большое расхождение часов сервера мешает проверке JWT.

### Не найден лист или диапазон

Актуальная версия создает помесячные листы автоматически. Проверьте права сервисного аккаунта на таблицу и убедитесь, что названия существующих месячных вкладок не были вручную изменены на нестандартные.

### Столбец уже занят

Перенесите существующие данные либо измените соответствующий номер столбца в `.env` (`TECHNICAL_ID_COLUMN`, `DEAL_LINK_COLUMN`, `DEAL_CREATED_COLUMN`, `DEAL_WON_DATE_COLUMN`, `DEAL_TYPE_COLUMN`, `INVOICE_DATE_COLUMN`).

### `Range ... exceeds grid limits. Max rows: 1000`

Обновите скрипт до версии с автоматическим расширением листа. Перед записью новой строки скрипт проверяет `rowCount` и при необходимости добавляет 1000 строк через Google Sheets API. В актуальной версии вручную увеличивать количество строк не требуется.

### Google возвращает `429` / `Write requests per minute per user`

Исчерпана минутная квота записи Google Sheets. Актуальная версия объединяет создание заголовков в один запрос, выдерживает общий интервал между записями и автоматически делает длинную паузу после `429`. Оставьте в `.env` безопасные значения:

```env
GOOGLE_WRITE_REQUEST_INTERVAL_MS=1200
GOOGLE_WRITE_RATE_FILE=var/google-write-rate.lock
```

Общий rate-файл координирует запись между основным и менеджерским cron, даже если они работают в отдельных PHP-процессах. Если ошибку уже получили, подождите 60–90 секунд и запустите команду снова. Для уменьшения общей нагрузки cron-запуски все равно желательно разнести по времени.

### Ручной запуск работает, а cron — нет

Используйте абсолютный путь к PHP, выполняйте `cd` в каталог проекта, проверьте пользователя cron и его права на `.env`, `credentials` и `var`.

## 12. Безопасность

- Не публикуйте `.env` и `credentials/google-service-account.json`.
- Не сохраняйте URL вебхука Битрикс24 в документации или Git.
- Используйте права `600` для `.env` и JSON-ключа.
- Запускайте cron от отдельного непривилегированного пользователя.
- Если вебхук или JSON-ключ попал в открытый доступ, немедленно отзовите и создайте новый.

## 13. Контрольный список

- `php -v` показывает нужную CLI-версию.
- Расширения `curl`, `json`, `mbstring`, `openssl` загружены.
- `.env` заполнен и закрыт правами `600`.
- JSON-ключ находится в `credentials/` и закрыт правами `600`.
- Google-таблица открыта для `client_email` сервисного аккаунта.
- Воронка и `STAGE_ID` указаны явно.
- `php tests/run.php` завершен без ошибок.
- `php bin/sync.php --diagnose` возвращает `status: ok`.
- `php bin/sync.php --full --dry-run` формирует ожидаемый план.
- `php bin/sync.php --full` заполняет таблицу.
- В cron используется абсолютный путь к PHP и каталогу проекта.
