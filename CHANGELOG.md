# Changelog

All notable changes to `stackmonitor/agent-laravel`. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versions follow
[Semantic Versioning](https://semver.org/). Every release needs its section
here, with the same version as `ReportBuilder::AGENT_VERSION`.

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
