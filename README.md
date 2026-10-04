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

Ab 1.5.0 meldet er die PHP-Einstellungen des Webservers: SAPI, OPcache mit Füllstand, `memory_limit`,
`max_execution_time`, `upload_max_filesize`, `post_max_size`, `display_errors`, `error_reporting`,
`date.timezone` und die Namen der geladenen Erweiterungen. Das Dashboard fragt per HTTP, die Werte der
Kommandozeile (Cron, Queue-Worker) können abweichen. Weil Laravel `display_errors` beim Start abschaltet,
meldet er dafür den Wert aus der PHP-Konfiguration: Er greift, wenn ein Fehler vor dem Start passiert.
Ist `opcache_get_status()` per `opcache.restrict_api` gesperrt, bleibt der Füllstand unbekannt.

## Geplante Aufgaben

Ab 1.7.0 meldet er jede geplante Aufgabe des Schedulers einzeln: Befehl bzw. Beschreibung, Cron-Ausdruck,
Zeitzone und den letzten Lauf mit Start, Ende, Ergebnis, Exit-Code, kurzer Fehlermeldung und den Dauern der
letzten 10 erfolgreichen Läufe. Er hört dafür auf die Ereignisse des Schedulers und merkt sich die Läufe im
Standard-Cache der App; bei Aufgaben mit `runInBackground()` zählt das Ende über `schedule:finish`. Eine Aufgabe
erkennt er am Befehl mit Argumenten (eine Closure an ihrer Beschreibung), nicht am Zeitplan. Läuft die App auf
mehreren Servern, muss der Cache geteilt sein (Redis, Datenbank), sonst sieht jeder Agent nur die Läufe seines
Servers – `onOneServer()` verlangt das ohnehin.

Die Liste der Aufgaben stammt ab 1.7.1 aus dem letzten `schedule:run`, nicht aus dem Request: Steht der Schedule
in `routes/console.php` (Standard seit Laravel 11), lädt nur die Konsole ihn. Bis zum ersten Lauf des Schedulers
nach der Installation fehlt die Liste deshalb.

## Fehlgeschlagene Logins

Ab 1.8.0 zählt er die fehlgeschlagenen Logins pro Stunde, damit das Dashboard den Verlauf zeigt und Ausreißer
markiert: wie viele fehlschlugen, wie viele davon für einen existierenden Benutzer (falsches Passwort) und von wie
vielen verschiedenen Adressen. Er hört dafür auf die Ereignisse `Attempting` und `Failed` von Laravels Auth und
merkt sich die Zahlen der letzten 14 Tage im Standard-Cache der App. Die App verlassen nur Zahlen, keine Adressen
und keine Benutzernamen; Adressen unterscheidet er innerhalb der Stunde an einem kurzen, mit `APP_KEY`
gebildeten Hash (höchstens 1000 pro Stunde), für vergangene Stunden bleibt nur ihre Anzahl. Bis die App
jemanden über Laravels Auth anmelden lässt (Breeze, Jetstream/Fortify, `Auth::attempt()`), fehlt der Teil;
Token-Guards wie der von Sanctum lösen diese Ereignisse nicht aus.

## Verdächtige Dateien

Ab 1.9.0 durchsucht er den Ordner der `public`-Disk (`storage/app/public`) mit allen Unterordnern und
`public/storage`, wenn das ein eigener Ordner statt des üblichen Links ist, nach Dateien, die PHP ausführen
können: PHP-Endungen (`.php`, `.phtml`, `.phar`, `.pht`, `.php3` bis `.php8`, `.phps`), eine PHP-Endung vor der
letzten (`bild.php.jpg`) und `.htaccess`-Dateien, die Dateien an PHP geben oder `php_flag engine on` setzen. Die App
verlassen nur Pfad, Größe und Änderungsdatum, nie der Inhalt. Eine `index.php`, die leer ist oder nur aus
Kommentaren besteht, lässt er aus; verlinkten Ordnern folgt er nicht. Der Scan läuft beim Report, je Report aber
höchstens 50.000 Einträge und 2 Sekunden; große Ordner werden so über mehrere Reports durchsucht, den Stand hält
der Standard-Cache der App. Ein neuer Scan beginnt, wenn das letzte Ergebnis 30 Minuten alt ist.

## Deploys

Ab 1.10.0 meldet er den letzten Deploy, damit das Dashboard ihn in seinen Diagrammen markiert. Er sieht nacheinander
nach:

1. **Deploy-Datei:** `.stackmonitor-deploy` im Projektordner (anderer Pfad über `STACKMONITOR_AGENT_DEPLOY_FILE`,
   relativ zum Projekt oder absolut). Ihre Änderungszeit ist der Deploy, ihre erste Zeile, wenn vorhanden, die
   Revision (höchstens 40 Zeichen). Das passt auch für Deploys ohne Git auf dem Server, etwa per rsync. Im
   Deploy-Skript reicht nach dem Kopieren der Dateien z. B. `git rev-parse HEAD > .stackmonitor-deploy` (lokal
   ausgeführt und mitkopiert) oder `touch .stackmonitor-deploy` auf dem Server.
2. **Git:** der Commit, auf den `HEAD` zeigt, mit der Zeit, zu der der Branch dorthin gewandert ist.
3. **Config-Cache:** wann `php artisan config:cache` zuletzt lief (`bootstrap/cache/config.php`), ohne Revision.

Ist nichts davon da, meldet er keinen Deploy.

## npm-Pakete

Ab 1.6.0 meldet er die JavaScript-Pakete der App, damit das Dashboard sie auf Sicherheitslücken prüfen kann.
Er liest dafür nur das Lockfile neben der `package.json`: `package-lock.json` (Version 1 bis 3),
`pnpm-lock.yaml` oder `yarn.lock`, in dieser Reihenfolge. Gemeldet werden alle installierten Pakete, auch
transitive, mit Version und ob sie nur für die Entwicklung gebraucht werden (höchstens 5000). `yarn.lock` und
pnpm ab Lockfile-Version 9 vermerken das nicht; dort gelten nur die direkten `devDependencies` aus der
`package.json` als Entwicklungs-Abhängigkeit. Liegt kein Lockfile auf dem Server, etwa weil die Assets in der
CI gebaut werden, meldet er das; ohne `package.json` entfällt der Teil.

## Sicherheit

- Jede Anfrage muss per HMAC-SHA256 signiert sein. Timestamp (±300 s) und Nonce werden geprüft, eine Nonce ist nur einmal gültig.
- Ungültige Anfragen beantwortet der Endpunkt mit `404` — demselben generischen 404, das Laravel auch für unbekannte Routen liefert, bei jeder HTTP-Methode, damit der Endpunkt nach außen nicht auffällt. Die Antwort auf eine gültige Anfrage ist ebenfalls signiert.
- Bekannte Einschränkung: Mit `APP_DEBUG=true` zeigt die Fehlerseite einer abgelehnten Anfrage den Stacktrace
  und darin den Agent. In Produktion gehört `APP_DEBUG` ohnehin auf `false`, sonst liegt die ganze Anwendung offen.
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
