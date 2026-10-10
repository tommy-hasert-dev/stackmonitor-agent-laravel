<picture>
  <source media="(prefers-color-scheme: dark)" srcset="art/stackmonitor-logo-on-dark.svg">
  <img src="art/stackmonitor-logo-on-light.svg" alt="StackMonitor" height="48">
</picture>

# StackMonitor Agent for Laravel

Read-only agent. It gives the StackMonitor dashboard the Laravel, PHP and package versions, the errors of the
last 24 hours from the log and operational data that only the server can see.
Supports Laravel 10–13 and PHP ≥ 8.1.

## Installation

1. `composer require stackmonitor/agent-laravel`
2. In the StackMonitor dashboard, open the site, then **Bearbeiten → Agent → Secret erzeugen** (Edit → Agent →
   Generate secret).
3. Add the line shown there to the site's `.env`:
   `STACKMONITOR_AGENT_SECRET=…`
4. Run `php artisan config:cache` if the config is cached. If the `.env` changes again later (e.g. a new
   secret, a different path), run `php artisan config:cache` again and, if routes are cached,
   `php artisan route:cache` — otherwise the old, cached configuration stays in effect.

Since 1.15.0 further secrets go comma-separated into `STACKMONITOR_AGENT_SECRETS`, one per StackMonitor
instance or account that monitors the app (an agency and its client, production and a local stack), five in all
with `STACKMONITOR_AGENT_SECRET`. To change a secret without a gap, add the new one there, switch the dashboard
to it and remove the old one afterwards.

The endpoint is `GET /stackmonitor/status`. The path can be changed with `STACKMONITOR_AGENT_PATH`; the agent URL in the dashboard then has to be changed to match.

## Errors in the log

The agent counts the entries of level `error` and above from the last 24 hours in the app's file log: in the
default channel if it is `single` or `daily`, otherwise in the first such channel of a stack. It reads at most
the last 5 MB. Other channels (stderr, Sentry, Papertrail …) it reports as "not readable".

It sends along the three most frequent messages, only the first line without context and stack trace, cut to
200 characters. Email addresses, URLs, quoted values, IDs, tokens, IP addresses, numbers, SQL and directories
are replaced first. Since 1.13.0 it names for each message where the exception was thrown: the Composer
package under `vendor/`, the framework or the app's own code. With `STACKMONITOR_AGENT_LOG_MESSAGES=false` in
the `.env` only the counts are sent.

## Operational data

Since 1.3.0 the agent also reports: failed jobs, pending jobs of the default queue (not with the `sync`
driver; only on Laravel versions with `pendingSize()`, otherwise "unknown"), when the scheduler last ran, free
disk space, migrations not yet run and whether config and routes are cached. For the scheduler it records each
start of `schedule:run` in the app's default cache; until the first run after installation it reports "no run
yet". Whatever it cannot read it reports as unknown.

Since 1.4.0 it also reports the last backup of `spatie/laravel-backup` if the app uses it: the newest backup
file on the configured disks with the `local` driver. It does not query remote disks (S3, FTP …), as that would
cost a network request on every report; if the app only backs up there, it reports the time as unknown.

Since 1.5.0 it reports the web server's PHP settings: SAPI, OPcache with fill level, `memory_limit`,
`max_execution_time`, `upload_max_filesize`, `post_max_size`, `display_errors`, `error_reporting`,
`date.timezone` and the names of the loaded extensions. The dashboard asks over HTTP; the command-line values
(cron, queue workers) may differ. Because Laravel turns `display_errors` off at boot, it reports the value from
the PHP configuration for it instead: that is what applies when an error happens before boot.
If `opcache_get_status()` is blocked by `opcache.restrict_api`, the fill level stays unknown.

Since 1.14.0 it reports the server's time, as the last value before sending, and the app's time zone
(`app.timezone`). From this the dashboard detects a server clock that is off.

## Scheduled tasks

Since 1.7.0 it reports every scheduled task of the scheduler individually: command or description, cron
expression, time zone and the last run with start, end, result, exit code, a short error message and the
durations of the last 10 successful runs. To do this it listens to the scheduler's events and records the runs
in the app's default cache; for tasks with `runInBackground()` the end is taken from `schedule:finish`. It
identifies a task by its command with arguments (a closure by its description), not by its schedule. If the
app runs on several servers, the cache has to be shared (Redis, database), otherwise each agent only sees the
runs on its own server – `onOneServer()` requires that anyway.

Since 1.7.1 the list of tasks comes from the last `schedule:run`, not from the request: if the schedule is
defined in `routes/console.php` (the default since Laravel 11), only the console loads it. Until the
scheduler's first run after installation the list is therefore missing.

## Failed logins

Since 1.8.0 it counts failed logins per hour, so the dashboard can show the trend and flag outliers: how many
failed, how many of those were for an existing user (wrong password) and from how many different addresses. To
do this it listens to the `Attempting` and `Failed` events of Laravel's auth and keeps the numbers of the last
14 days in the app's default cache. Only numbers leave the app, no addresses and no user names; within the hour
it tells addresses apart by a short hash built with `APP_KEY` (at most 1000 per hour), for past hours only
their count remains. As long as the app doesn't log anyone in through Laravel's auth (Breeze,
Jetstream/Fortify, `Auth::attempt()`), this part is missing; token guards such as Sanctum's don't fire these
events.

## Suspicious files

Since 1.9.0 it searches the folder of the `public` disk (`storage/app/public`) with all subfolders, and
`public/storage` if that is a folder of its own instead of the usual link, for files that can execute PHP: PHP
extensions (`.php`, `.phtml`, `.phar`, `.pht`, `.php3` to `.php8`, `.phps`), a PHP extension before the last
one (`image.php.jpg`) and `.htaccess` files that hand files to PHP or set `php_flag engine on`. Only path, size
and modification date leave the app, never the contents. It skips an `index.php` that is empty or consists only
of comments; it doesn't follow linked folders. The scan runs during the report, but per report for at most
50,000 entries and 2 seconds; large folders are thus searched over several reports, with the progress kept in
the app's default cache. A new scan starts once the last result is 30 minutes old.

Since 1.12.0 the dashboard may read the beginning (at most 64 KB) of a suspicious file the agent found itself,
and checks it for signs of malicious code. This is off until the app allows it with
`STACKMONITOR_AGENT_FILE_CONTENTS=true` in the `.env`; the dashboard cannot turn it on. Only files from the
agent's last complete scan can be read, requested by the SHA-256 of their path: no other file, none that has
changed since the scan, and none that is a link. Whether it is allowed is stated in the report under
`suspicious_files.contents`. The request is signed like any other, including the path hash.

## Deploys

Since 1.10.0 it reports the last deploy, so the dashboard can mark it in its charts. It checks, in this order:

1. **Deploy file:** `.stackmonitor-deploy` in the project folder (a different path via
   `STACKMONITOR_AGENT_DEPLOY_FILE`, relative to the project or absolute). Its modification time is the deploy,
   its first line, if present, the revision (at most 40 characters). This also works for deploys without Git on
   the server, e.g. via rsync. In the deploy script, after copying the files, e.g.
   `git rev-parse HEAD > .stackmonitor-deploy` (run locally and copied along) or `touch .stackmonitor-deploy`
   on the server is enough.
2. **Git:** the commit `HEAD` points to, with the time the branch moved there.
3. **Config cache:** when `php artisan config:cache` last ran (`bootstrap/cache/config.php`), without a revision.

If none of these is present, it reports no deploy.

## Database size

Since 1.11.0 it reports the size of the default connection's database, so the dashboard shows the growth before
the host's limit is reached: the total size (data and indexes of all tables), the number of tables and the 10
largest with size and approximate row count, never their contents. MySQL and MariaDB it reads from
`information_schema`, or with `SHOW TABLE STATUS` if the host blocks that; views don't count. With SQLite the
size of the file counts, and it reads the tables from `dbstat` if SQLite was built with it; SQLite doesn't
estimate a row count. It measures about once a day (every 23 hours, so no day is skipped with hourly reports),
with the result kept in the app's default cache; if the measurement fails, it tries again after an hour. For
other drivers (PostgreSQL, SQL Server) and an in-memory database the value is missing.

## npm packages

Since 1.6.0 it reports the app's JavaScript packages, so the dashboard can check them for vulnerabilities.
For this it only reads the lockfile next to `package.json`: `package-lock.json` (versions 1 to 3),
`pnpm-lock.yaml` or `yarn.lock`, in that order. It reports all installed packages, transitive ones included,
with version and whether they are only needed for development (at most 5000). `yarn.lock` and pnpm from
lockfile version 9 don't record that; there only the direct `devDependencies` from `package.json` count as
development dependencies. If there is no lockfile on the server, e.g. because the assets are built in CI, it
reports that; without a `package.json` this part is left out.

## Security

- Every request has to be signed with HMAC-SHA256. Timestamp (±300 s) and nonce are checked; a nonce is only valid once.
- The endpoint answers invalid requests with `404` — the same generic 404 that Laravel returns for unknown routes, for every HTTP method, so the endpoint doesn't stand out from the outside. The response to a valid request is signed as well.
- Known limitation: with `APP_DEBUG=true`, the error page of a rejected request shows the stack trace and the
  agent in it. In production `APP_DEBUG` belongs on `false` anyway, otherwise the whole application is exposed.
- The agent is read-only: no write actions, no session, no cookies.
- For replay protection it uses the application's default cache (`cache.default`). This cache has to be
  **persistent and, with several application nodes, shared** (e.g. Redis or the database driver) — the
  `array` and `null` drivers don't protect against replays: `array` forgets used nonces with the next request
  process, `null` stores nothing at all, and with several nodes without a shared cache each node only sees the
  nonces already used on itself.

## Development

Like everything local, it runs in the `tools` container (see the README in the repository root), from the
repository root:

```bash
docker compose run --rm -w /app/agents/laravel tools composer install
docker compose run --rm -w /app/agents/laravel tools composer test   # Pint and Pest
```

## Changes

What changed between versions is in [`CHANGELOG.md`](CHANGELOG.md) and in the GitHub releases.

## License

MIT, see `LICENSE`.
