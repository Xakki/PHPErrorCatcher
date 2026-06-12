# PHPErrorCatcher — Claude guide

PHP 8.3+ library (`xakki/phperrorcatcher`, PSR-3) that catches and logs all PHP & JS errors to pluggable storage backends. GPL-3.0-or-later. Pure library — there is no app to "run"; exercise it through tests and `example/test.php`.

## Architecture (`src/`, PSR-4 `Xakki\PhpErrorCatcher\`)

- `PhpErrorCatcher.php` — entry point; registers handlers, dispatches errors.
- `Base.php`, `Tools.php` — shared base + helpers.
- `dto/` — `AbstractData`, `HttpData`, `LogData` (error payloads / value objects).
- `storage/` — backends extending `BaseStorage`: `FileStorage`, `StreamStorage`, `SyslogStorage`, `PdoStorage`, `ElasticStorage`. One error can fan out to several storages.
- `viewer/` — `FileViewer` + templates in `viewer/file/` (HTML report rendering).
- `plugin/` — `BasePlugin`, `JsLogPlugin` (browser-side error capture).
- `connector/` — framework bridges (e.g. `YiiLogger`).
- `contract/` — interfaces (`CacheInterface`).

Tests live in `tests/` (PSR-4 `Xakki\PhpErrorCatcher\Tests\`, PHPUnit 12).

## Commands — all via `make`

Run `make help` for the full list. Key targets:

| Target                                | Does                                    |
|---------------------------------------|-----------------------------------------|
| `make check`                          | Full QA: code style + phpstan + tests   |
| `make test`                           | PHPUnit (default image, PHP 8.3)        |
| `make test-php PHP=8.4`               | PHPUnit on a specific PHP (8.3/8.4/8.5) |
| `make test-php-all`                   | PHPUnit on the whole 8.3/8.4/8.5 matrix |
| `make phpstan`                        | PHPStan (level 8)                       |
| `make cs-check` / `make cs-fix`       | phpcs check / phpcbf autofix            |
| `make lint [file=…]`                  | `php -l` syntax check                   |
| `make composer-i` / `make composer-u` | composer install / update               |

**Everything runs inside Docker** (image `phperrorcatcher`, built on `php:8.3-cli-alpine`; build once with `make docker-build`, or `make docker-build PHP=8.5` for another version). **All operations go through `make` — if a target is missing, add it to the `Makefile`; do not call `php` / `composer` / `phpunit` / `phpstan` / `phpcs` / `docker` directly.**

## Conventions & process

- PHP **8.3+**; CI runs PHPUnit and PHPStan on the 8.3/8.4/8.5 matrix, phpcs on 8.3 (`.github/workflows/ci.yml`). Tooling: PHPUnit 12, PHPStan 2 (level 8), phpcs 3.13.
- Code style: `opsway/psr12-strict` via phpcs (config `phpcs.xml`). PHPStan **level 8** (`phpstan.neon`; `connector/`, `ElasticStorage`, `PdoStorage` are excluded from analysis).
- Branches: `master` (primary) and `master56`. CI runs on both.
- Comments minimal — explain *why*, not *what*; let names speak. Match surrounding style (existing code comments are in Russian).

### Releases
- ⚠️ The version is stored in **both** `composer.json` and `package.json`, and they have **drifted apart** — keep them in sync, set both to the same value.
- Tags: recent releases use **no `v` prefix** (`0.8.0`, `0.5.0`); older `vX.Y.Z` tags are legacy.
- Use `/release` for the full bump → QA → commit → tag flow.

## Don't index (read only if truly needed)

`vendor/`, `composer.lock`, `.phpcs-cache`, `.phpunit.result.cache`, `.idea/`.
