# StackMonitor Agent für Laravel

Nur lesender Agent. Er liefert dem StackMonitor-Dashboard Laravel-, PHP- und Paketversionen, die Fehler
der letzten 24 Stunden aus dem Log und Betriebsdaten, die nur der Server sieht.
Unterstützt Laravel 10–13 und PHP ≥ 8.1.

## Installation

1. `composer require stackmonitor/agent-laravel`
2. Im StackMonitor-Dashboard die Site öffnen, dann **Bearbeiten → Agent → Secret erzeugen**.
3. Die angezeigte Zeile in die `.env` der Site eintragen:
   `STACKMONITOR_AGENT_SECRET=…`
4. `php artisan config:cache` ausführen, falls die Config gecacht wird. Wird die `.env` später erneut geändert
   (z. B. neues Secret, anderer Pfad), müssen `php artisan config:cache` und, falls Routen gecacht sind,
   `php artisan route:cache` erneut ausgeführt werden — sonst greift weiterhin die alte, gecachte Konfiguration.

Der Endpunkt ist `GET /stackmonitor/status`. Der Pfad lässt sich mit `STACKMONITOR_AGENT_PATH` ändern, dann muss die Agent-URL im Dashboard angepasst werden.

## Fehler im Log

Der Agent zählt die Einträge ab Level `error` der letzten 24 Stunden im Datei-Log der App: im Default-Kanal,
wenn er `single` oder `daily` ist, sonst im ersten solchen Kanal eines Stacks. Gelesen werden höchstens die
letzten 5 MB. Andere Kanäle (stderr, Sentry, Papertrail …) meldet er als „nicht auswertbar“.

Mitgeschickt werden die drei häufigsten Meldungen, nur die erste Zeile ohne Kontext und Stacktrace, auf 200
Zeichen gekürzt. E-Mail-Adressen, URLs, Werte in Anführungszeichen, IDs, Tokens, IP-Adressen, Zahlen, SQL und
Verzeichnisse ersetzt er vorher. Mit `STACKMONITOR_AGENT_LOG_MESSAGES=false` in der `.env` gehen nur die
Anzahlen raus.

## Betriebsdaten

Ab 1.3.0 meldet der Agent zusätzlich: fehlgeschlagene Jobs, wartende Jobs der Standard-Queue (nicht beim
Treiber `sync`; erst ab Laravel-Versionen mit `pendingSize()`, sonst „unbekannt“), wann der Scheduler zuletzt lief, freien Speicherplatz, noch nicht gelaufene Migrationen und ob
Config und Routen gecacht sind. Für den Scheduler merkt er sich jeden Start von `schedule:run` im Standard-Cache
der App; bis zum ersten Lauf nach der Installation meldet er „noch kein Lauf“. Was er nicht lesen kann, meldet er
als unbekannt.

Ab 1.4.0 meldet er außerdem die letzte Sicherung von `spatie/laravel-backup`, falls die App es nutzt: die neueste
Backup-Datei auf den konfigurierten Disks mit dem Treiber `local`. Entfernte Disks (S3, FTP …) fragt er nicht ab,
das kostete bei jedem Bericht einen Netzwerkzugriff; sichert die App nur dorthin, meldet er den Zeitpunkt als
unbekannt.

## Sicherheit

- Jede Anfrage muss per HMAC-SHA256 signiert sein. Timestamp (±300 s) und Nonce werden geprüft, eine Nonce ist nur einmal gültig.
- Ungültige Anfragen beantwortet der Endpunkt mit `404` — demselben generischen 404, das Laravel auch für unbekannte Routen liefert, damit der Endpunkt nach außen nicht auffällt. Die Antwort auf eine gültige Anfrage ist ebenfalls signiert.
- Der Agent ist nur lesend: keine schreibenden Aktionen, keine Session, keine Cookies.
- Für den Replay-Schutz nutzt er den Standard-Cache der Anwendung (`cache.default`). Dieser Cache muss
  **persistent und, bei mehreren Anwendungs-Knoten, geteilt** sein (z. B. Redis oder der Datenbank-Treiber) —
  die Treiber `array` und `null` schützen nicht vor Replays: `array` vergisst genutzte Nonces beim nächsten
  Request-Prozess, `null` speichert gar nichts, und bei mehreren Knoten ohne geteilten Cache sieht jeder Knoten
  nur seine eigenen bereits genutzten Nonces.

## Entwicklung

Läuft wie alles lokal im `tools`-Container (siehe README im Wurzelverzeichnis), aus dem Wurzelverzeichnis:

```bash
docker compose run --rm -w /app/agents/laravel tools composer install
docker compose run --rm -w /app/agents/laravel tools composer test   # Pint und Pest
```

## Änderungen

Was sich zwischen den Versionen geändert hat, steht in [`CHANGELOG.md`](CHANGELOG.md) (Englisch) und in den
GitHub-Releases.

## Lizenz

MIT, siehe `LICENSE`.
