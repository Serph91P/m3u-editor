# Reproduzierbares Docker-Lokalsetup für Entwicklung

Diese Anleitung beschreibt das im Repository vorhandene Setup für lokale Entwicklung und Tests. Sie leitet die Befehle aus `docker-compose.dev.yml`, `Dockerfile`, `docker/run-tests` und `.github/workflows/ci.yml` ab. Die Testumgebung verwendet keine Live-Schedules-Direct-Zugangsdaten; HTTP-Aufrufe werden in den Tests gefakt.

## Voraussetzungen

- Docker Engine mit `docker compose` und BuildKit/Buildx
- Ausreichend Speicher für den mehrstufigen Docker-Build
- Keine projektweite `.env` erforderlich für den Test-Container

## Entwicklungscontainer

Vom Repository-Root aus:

```sh
docker compose -f docker-compose.dev.yml up --build
```

Die Anwendung ist danach unter `http://localhost:36450` erreichbar. Der Healthcheck prüft `/up` auf Port `36450`. Der Container baut das Image aus dem lokalen `Dockerfile`; Composer- und npm-Abhängigkeiten sowie das Frontend werden im Image erstellt. Änderungen an PHP-, Frontend- oder Konfigurationsdateien erfordern daher einen erneuten Build.

Der Stack enthält im Entwicklungscontainer PostgreSQL, Redis und m3u-proxy als eingebettete Dienste. Die Compose-Datei verwendet ausschließlich Entwicklungswerte (`devpass`) und Docker-managed Volumes:

- `configdata_dev` für Konfigurationsdaten
- `pgdata_dev` für PostgreSQL
- `clamavdata_dev` für optionale ClamAV-Signaturen

Für einen vollständig frischen Lauf einschließlich dieser Volumes:

```sh
docker compose -f docker-compose.dev.yml down -v
docker compose -f docker-compose.dev.yml up --build
```

`down -v` löscht die genannten Entwicklungsdaten. Nicht gegen produktive Compose-Projekte oder produktive Volumes ausführen.

## Isolierter Testlauf

Der Testdienst wird über das Compose-Profil `test` aktiviert und baut mit `INSTALL_DEV_DEPENDENCIES=true`:

```sh
docker compose -f docker-compose.dev.yml --profile test build m3u-editor-dev-test
docker compose -f docker-compose.dev.yml --profile test run --rm m3u-editor-dev-test
```

Für einen gezielten Test oder Filter können Argumente an Artisan weitergereicht werden:

```sh
docker compose -f docker-compose.dev.yml --profile test run --rm m3u-editor-dev-test \
  --filter='SchedulesDirect'
```

Der Entrypoint `docker/run-tests` legt bei jedem Lauf `database/jobs.sqlite` neu an und startet anschließend `php artisan test`. Die Testumgebung ist absichtlich ohne externe Redis-/PostgreSQL-Dienste konfiguriert: SQLite (`:memory:`), Array-Cache, synchrone Queue, deaktiviertes Redis und deaktivierte Pulse-/Telescope-Funktionen. Reverb erhält sichere Dummy-Werte (`test`); es werden keine Secrets benötigt.

Wenn ein Lauf wegen eines SQLite-Locks oder eines beschädigten Testzustands auffällig ist, den einmaligen Container entfernen und neu erzeugen:

```sh
docker compose -f docker-compose.dev.yml --profile test rm -sf m3u-editor-dev-test
docker compose -f docker-compose.dev.yml --profile test run --rm m3u-editor-dev-test
```

Keine Testdatenbank oder `.env` in das Repository committen.

## Relevante CI-Entsprechung

Der CI-Testjob nutzt PHP 8.4, PostgreSQL 16 und Redis als GitHub-Servicecontainer. Die reproduzierbaren Schritte sind:

```sh
composer install --no-interaction --prefer-dist --no-progress
npm ci
npm run build
cp .env.example .env
echo 'REVERB_APP_SECRET=123' >> .env
php artisan key:generate
touch database/jobs.sqlite
php artisan migrate --force
php artisan plugins:discover
php artisan plugins:validate
php artisan test --parallel --processes=4 --recreate-databases
```

Lokal ohne laufende PostgreSQL-/Redis-Servicecontainer ist deshalb der isolierte Compose-Testdienst der passende Testweg. Für eine CI-nahe Ausführung müssen PostgreSQL 16 und Redis separat bereitgestellt und die Variablen `DB_CONNECTION=pg_test`, `TEST_DB_DATABASE=m3ue_test`, `TEST_DB_USERNAME=testing`, `TEST_DB_PASSWORD=testing`, `REDIS_HOST=127.0.0.1` und `REDIS_SERVER_PORT=6379` gesetzt werden. Es dürfen dafür nur lokale Testdatenbanken verwendet werden.

## Schedules-Direct-Entwicklung ohne Live-Konto

- Keine Schedules-Direct-Token, Cookies oder Kontodaten in `.env`, Compose-Dateien oder Tests hinterlegen.
- Provider-Antworten ausschließlich über Laravel-HTTP-Fakes/Request-Mappings in den gezielten Tests simulieren.
- Erfolgs-, bereits abonnierte und Add/Remove-Fälle mit deterministischen Fixtures prüfen.
- Den dokumentierten Program-Subchunk-Fehlerpfad getrennt prüfen: Fehlercode `6001` ist retrybar; `6000` und andere permanente Fehler dürfen nicht still als Erfolg behandelt werden.

## Aufräumen und Diagnose

Status und Logs:

```sh
docker compose -f docker-compose.dev.yml ps
docker compose -f docker-compose.dev.yml logs --no-color m3u-editor-dev
docker compose -f docker-compose.dev.yml logs --no-color m3u-editor-dev-test
```

Stoppen ohne Volumes zu löschen:

```sh
docker compose -f docker-compose.dev.yml down
```

Der Entwicklungsstack ist kein Produktions-Deployment. Insbesondere sind die eingebetteten Passwörter, der lokale Port und die Dummy-Konfiguration ausschließlich für lokale Entwicklung und Tests bestimmt.
