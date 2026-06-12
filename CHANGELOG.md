# Changelog

## [0.8.4] - 2026-06-12

### Changed

- **BC: PdoStorage rewritten** — writes the canonical record schema
  (`buildRecord()`: datetime, level, level_name, channel, message, context, extra, host, url);
  supports mysql/mariadb, postgresql, sqlite.
- Default table name changed from `_myprof` to `php_error_log`.
- `getPdo()` now accepts: a ready `PDO` object, an array of connection params
  (`engine/host/port/dbname/username/passwd`; sqlite uses `engine`+`path`), or
  a `callable` returning a `PDO` (lazy connect).
- Removed legacy profiler viewer code (`viewRenderBD()`, `createDB()`).
- `PdoStorage` removed from PHPStan `excludePaths`; passes level 8.
- Version bumped in `composer.json` and `package.json`.
