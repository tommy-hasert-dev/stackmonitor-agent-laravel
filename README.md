# StackMonitor Agent für Laravel

Nur lesender Agent. Er liefert dem StackMonitor-Dashboard Laravel-, PHP- und Paketversionen.
Unterstützt Laravel 10–13 und PHP ≥ 8.1.

## Installation (bis zur Veröffentlichung als Paket)

1. Den Ordner `agents/laravel` in das Kundenprojekt kopieren, z. B. nach `packages/stackmonitor-agent-laravel`.
   Dabei `vendor/`, `tests/`, `composer.lock` sowie die Entwicklungs-Dateien `phpunit.xml`, `pint.json`,
   `.gitattributes` und `.gitignore` auslassen — sie werden im Kundenprojekt nicht gebraucht. Entweder gezielt
   mit `rsync -a --exclude=vendor --exclude=tests --exclude=composer.lock agents/laravel/ packages/stackmonitor-agent-laravel/`
   kopieren, oder — sofern das Paket als eigenes Git-Repository vorliegt und `.gitattributes` die
   `export-ignore`-Einträge dieses Verzeichnisses enthält — mit `git archive` einen sauberen Snapshot exportieren.
2. In der `composer.json` des Kundenprojekts:
   ```json
   "repositories": [
       { "type": "path", "url": "packages/stackmonitor-agent-laravel", "options": { "symlink": false } }
   ]
   ```
3. `composer require stackmonitor/agent-laravel:@dev`
4. Im StackMonitor-Dashboard die Site öffnen, dann **Bearbeiten → Agent → Secret erzeugen**.
5. Die angezeigte Zeile in die `.env` der Site eintragen:
   `STACKMONITOR_AGENT_SECRET=…`
6. `php artisan config:cache` ausführen, falls die Config gecacht wird. Wird die `.env` später erneut geändert
   (z. B. neues Secret, anderer Pfad), müssen `php artisan config:cache` und, falls Routen gecacht sind,
   `php artisan route:cache` erneut ausgeführt werden — sonst greift weiterhin die alte, gecachte Konfiguration.

Der Endpunkt ist `GET /stackmonitor/status`. Der Pfad lässt sich mit `STACKMONITOR_AGENT_PATH` ändern, dann muss die Agent-URL im Dashboard angepasst werden.

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
