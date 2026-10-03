# Changelog

All notable changes to `stackmonitor/agent-laravel`. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versions follow
[Semantic Versioning](https://semver.org/). Every release needs its section
here, with the same version as `ReportBuilder::AGENT_VERSION`.

## [1.7.1] - 2026-10-03

### Fixed

- `scheduled_tasks` was always empty for apps that define their schedule in
  `routes/console.php` (the default since Laravel 11), because only the
  console kernel loads that file. The agent now keeps the tasks as
  `schedule:run` sees them in the cache and reports them from there. Until
  the first scheduler run after the update the field is `null` when the
  request itself sees no schedule.

## [1.7.0] - 2026-10-03

### Added

- Reports every task of the app's scheduler in the new field
  `scheduled_tasks`: command or description, cron expression, time zone,
  whether it runs in the background, since when it is scheduled and its last
  run with start, end, result, exit code, a short error message and the
  durations of the last 10 successful runs. The agent keeps them in the app's
  default cache from the scheduler's events; a background task ends with
  `schedule:finish`. Tasks for other environments are left out.

## [1.6.1] - 2026-10-03

### Fixed

- Requests to the endpoint with an HTTP method outside Laravel's own verbs
  (`PROPFIND`, `TRACE` or a made-up one) get the same generic `404` as an
  unknown path instead of Laravel's `405`, which revealed that the endpoint
  exists.

## [1.6.0] - 2026-10-02

### Added

- Reports the JavaScript packages from the app's lockfile in the new field
  `npm`: `package-lock.json` (v1 to v3), `pnpm-lock.yaml` or `yarn.lock`, every
  installed package with its version and whether it is only a development
  dependency. Without a lockfile on the server `lockfile` is `null`; without a
  `package.json` the field is left out.

## [1.5.0] - 2026-10-02

### Added

- Reports the PHP settings of the web server in `extra.php_config`: SAPI,
  OPcache with its fill level, `memory_limit`, `max_execution_time`,
  `upload_max_filesize`, `post_max_size`, `display_errors` from the PHP
  configuration, `error_reporting`, `date.timezone` and the names of the
  loaded extensions.

## [1.4.0] - 2026-10-02

### Added

- Reports the newest `spatie/laravel-backup` file on the local backup disks in
  `extra.backup`, without network access.

## [1.3.0] - 2026-10-02

### Added

- Reports operational data in `extra`: failed and waiting jobs, a heartbeat of
  its own scheduler, free disk space, pending migrations and whether config
  and routes are cached.

## [1.2.0] - 2026-10-02

### Added

- Reports the errors of the last 24 hours from the application log in the new
  field `error_log`: count, the log's file name, whether the read limit cut it
  short and the three most frequent messages, cleaned and capped at 200
  characters.
- Reads `single` and `daily` channels, also inside a `stack`, at most the last
  5 MB of the file.
- `STACKMONITOR_AGENT_LOG_MESSAGES=false` sends counts only.

## [1.1.0] - 2026-09-28

### Added

- Reports per package whether it is listed on Packagist, so the dashboard
  links only packages that are there.

## [1.0.0] - 2026-09-26

### Added

- First release: read-only agent that reports Laravel, PHP and Composer
  package versions over signed requests with replay protection.
