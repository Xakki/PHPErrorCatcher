# PHPErrorCatcher

Лёгкая PSR-3-совместимая библиотека для перехвата и логирования всех типов ошибок в PHP-приложениях: `trigger_error`, исключений, fatal-ошибок, пользовательских вызовов логгера и JS-ошибок из браузера.

Ветка `master56` поддерживает **PHP 5.6+** (основная ветка `master` работает на современных версиях PHP). Точку входа предоставляет класс `Xakki\PhpErrorCatcher\PhpErrorCatcher`.

## Возможности

* Регистрация глобальных обработчиков `set_error_handler`, `set_exception_handler`, `register_shutdown_function`.
* PSR-3 интерфейс (`debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`, `emergency`).
* Несколько хранилищ одновременно (`FileStorage`, `SyslogStorage`, `StreamStorage`, можно добавить свои).
* Плагины (`JsLogPlugin` для перехвата ошибок из браузера) и вьюверы (`FileViewer` — просмотр логов через web).
* Маскирование чувствительных полей (`password`, `pass`, …) в `$_POST`/`$_COOKIE`/`$_SESSION`.
* Фильтрация по правилам `ignoreRules` / `stopRules`, дедупликация по `logKey`, ограничение трейса по уровню.
* Цветной вывод в STDERR/STDOUT для CLI и HTML-вывод в `debugMode`.

## Установка

```bash
composer require xakki/phperrorcatcher
```

или подключение напрямую:

```php
include_once "src/PhpErrorCatcher.php";
```

## Быстрый старт

```php
use Xakki\PhpErrorCatcher\PhpErrorCatcher;

PhpErrorCatcher::init([
    'debugMode'  => isset($_COOKIE['changeMe']),
    'dirRoot'    => __DIR__,
    'logFields'  => ['project' => 'my-app'],
    'storage' => [
        'FileStorage' => [
            'logPath' => __DIR__ . '/runtime/log',
        ],
    ],
]);
```

Полный пример с плагином и вьювером — `example/test.php`.

## Ручное логирование

```php
PhpErrorCatcher::logger(PhpErrorCatcher::LEVEL_ERROR, 'My custom error');
PhpErrorCatcher::init()->error('Something went wrong', ['tag' => 'payments']);
trigger_error('legacy warning', E_USER_WARNING);

try {
    // ...
} catch (\Throwable $e) {
    PhpErrorCatcher::init()->critical($e);
}
```

Дополнительные хелперы: `addGlobalTag()`, `addGlobalField()`, `setViewAlert()`, `funcStart()` / `funcEnd()` для замера времени.

## Логирование ошибок браузера

```html
<script src="catcher.js"></script>
<script>errorCatcher('custom error');</script>
```

Скрипт ловит `window.onerror` и шлёт POST на текущий URL с GET-параметром, заданным в `JsLogPlugin.initGetKey`.

## Конфигурация

Параметры передаются массивом в `PhpErrorCatcher::init([...])`. Основные ключи:

| Ключ | Назначение |
| --- | --- |
| `debugMode` | Печать логов в STDERR/STDOUT/HTML, расширенные сообщения |
| `dirRoot` | Базовый путь для нормализации путей в трейсе |
| `globalTag` / `logFields` | Поля, которые добавляются ко всем логам |
| `enablePostLog`, `enableCookieLog`, `enableSessionLog` | Что прикладывать к запросу |
| `safeParamsKey` | Ключи, чьи значения маскируются как `***` |
| `logTraceByLevel` | Глубина backtrace для каждого уровня |
| `saveLogIfHasError` | Уровни, при которых лог сбрасывается на диск |
| `ignoreRules` / `stopRules` | Фильтры по `level`/`type`/`message` |
| `cacheServers`, `memcacheId`, `lifeTime` | Опциональный Memcached |
| `storage` | Карта `Имя => конфиг` (обязательный) |
| `plugin` | Карта плагинов |
| `viewer` | Конфиг вьювера (`class`, `initGetKey`) |

## Архитектура

```
PhpErrorCatcher (singleton, PSR-3 LoggerInterface)
├── Storages (BaseStorage[]) — куда писать логи
│   ├── FileStorage   — буфер в tmp-файл, сброс в plog при наличии ошибки
│   ├── SyslogStorage — UDP-сокет в syslog (RFC 5424)
│   └── StreamStorage — NDJSON в STDOUT/STDERR (формат Monolog\Formatter\JsonFormatter)
├── Plugins (BasePlugin[])
│   └── JsLogPlugin   — приём JS-ошибок через POST
├── Viewer (BaseViewer)
│   └── FileViewer    — web-просмотр логов из FileStorage
└── DTO
    ├── LogData       — одна запись лога (level, message, trace, file, fields, microtime)
    └── HttpData      — снапшот HTTP-запроса
```

* `Base` — общий предок для storage/plugin/viewer: хранит ссылку на `$owner`, реализует `applyConfig()` и магические геттеры через `__call`.
* `PhpErrorCatcher::log()` — единая точка: применяет `ignoreRules`, считает уровни, рендерит трейс, формирует `LogData` и отдаёт в каждое хранилище.
* `FileStorage` пишет события сначала в уникальный tmp-файл, на shutdown / по таймауту складывает их одним JSON-объектом в `logPath/logDir/Y.m/d.<level>.<tag>.plog`. Файлы ротируются по `limitFileSize`.
* `SyslogStorage` режет сообщение под `logSize` (по умолчанию 1400 байт UDP) и шлёт фрейм RFC 5424 на `remoteIp:remotePort`.
* `StreamStorage` пишет каждую запись одной NDJSON-строкой в `php://stderr` или `php://stdout`. Формат строки повторяет `Monolog\Formatter\JsonFormatter` (поля `message`, `context`, `level`, `level_name`, `channel`, `datetime`, `extra`), без зависимости от Monolog. Используется для контейнерных логов: их подбирает fluent-bit и шлёт в Graylog по GELF.
* `JsLogPlugin` активируется при наличии `$_GET[initGetKey]`, превращает POST из `catcher.js` в обычный `log()`-вызов.
* `FileViewer` показывает HTML-список логов; требует `FileStorage` и активируется через `$_GET[initGetKey]`.

## Логирование в Graylog через Docker + fluent-bit

`StreamStorage` пишет JSON-логи в STDOUT/STDERR в формате Monolog (`JsonFormatter`, NDJSON). Контейнерные логи Docker подбираются fluent-bit и пересылаются в Graylog по GELF.

```php
PhpErrorCatcher::init([
    'dirRoot' => __DIR__,
    'storage' => [
        'StreamStorage' => [
            'stream'        => 'php://stderr', // или 'php://stdout'
            'splitByLevel'  => true,           // warning+ → stderr, ниже → stdout
            'minLevelInt'   => LOG_DEBUG,
            'channel'       => 'my-app',
            'extraFields'   => [
                'service' => 'my-app',
                'env'     => getenv('APP_ENV'),
            ],
        ],
    ],
]);
```

Пример строки в stderr:

```json
{"message":"division by zero","context":{"log_type":"trigger","file":"index.php:42","trace":"…"},"level":400,"level_name":"ERROR","channel":"my-app","datetime":"2026-04-27T10:00:00.123456+00:00","extra":{"hostname":"web-1","pid":17,"ver":"0.6.0","service":"my-app","env":"prod"}}
```

Под PHP-FPM, чтобы логи попали в stdout/stderr контейнера, в `www.conf`:

```ini
catch_workers_output = yes
decorate_workers_output = no
```

Минимальная конфигурация fluent-bit (parser + output):

```ini
[INPUT]
    Name        forward

[FILTER]
    Name        parser
    Match       *
    Key_Name    log
    Parser      json
    Reserve_Data On

[OUTPUT]
    Name        gelf
    Match       *
    Host        graylog.example.com
    Port        12201
    Mode        udp
    Gelf_Short_Message_Key message
    Gelf_Timestamp_Key datetime
    Gelf_Level_Key level
    Gelf_Host_Key  hostname
```

## Разработка

```bash
make docker-build   # сборка образа phperrorcatcher56 (php:5.6-cli-alpine)
make composer-i     # composer install в контейнере
make cs-check       # phpcs (правила в phpcs.xml)
make cs-fix         # phpcbf
make psalm          # статический анализ (psalm.xml)
```

## Лицензия

MIT — см. `LICENSE.md`.
