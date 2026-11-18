# CKAN Scheduler

Client CLI PHP per l'estrazione automatica di dati da PostgreSQL e il caricamento su portale CKAN tramite API.

---

## Struttura del progetto

```
ckan-scheduler/
├── bin/
│   ├── scheduler.php              ← Esecuzione reale (estrae + carica su CKAN)
│   └── dry-run.php                ← Simulazione (estrae ma NON carica su CKAN)
├── config/
│   ├── common.ini                 ← Parametri comuni (retry, tabelle, scheduling)
│   └── env/
│       ├── production.ini.example ← Modello: copiare in production.ini (credenziali produzione)
│       └── test.ini.example       ← Modello: copiare in test.ini (credenziali test)
├── src/
│   ├── autoload.php               ← Autoloader PSR-4 (no Composer)
│   ├── Application.php            ← Orchestratore principale
│   ├── Config/
│   │   ├── Config.php             ← Value object configurazione
│   │   └── IniConfigLoader.php    ← Carica e fonde i file .ini
│   ├── Database/
│   │   ├── Connection.php         ← Wrapper PDO
│   │   └── ConnectionPool.php     ← Pool multi-database (un DB per schema)
│   ├── Enum/
│   │   └── FileExtension.php      ← Enum: csv | json | rdf | geojson | xlsx
│   ├── Exception/
│   │   ├── CkanApiException.php
│   │   ├── ExtractionException.php
│   │   ├── FilesystemException.php
│   │   └── LockException.php
│   ├── Http/
│   │   └── RetryHttpClient.php    ← cURL con retry esponenziale
│   ├── Lock/
│   │   └── FileLock.php           ← Lock filesystem anti-sovrapposto
│   ├── Logging/
│   │   └── ErrorLogger.php        ← Scrittura errori su PostgreSQL
│   ├── Model/
│   │   └── SchedulationRecord.php ← Value object da all_schedulation
│   ├── Repository/
│   │   └── ResourceLogRepository.php ← Upsert resource_upload_log
│   ├── Service/
│   │   ├── UploaderInterface.php  ← Contratto upload
│   │   ├── CkanUploader.php       ← Upload reale via API CKAN
│   │   ├── DryRunUploader.php     ← Simulazione con report a terminale
│   │   └── DataExtractor.php      ← Esegue query e scrive file su disco
│   └── Writer/
│       ├── XlsxWriter.php         ← Generatore XLSX senza dipendenze
│       └── ZipBuilder.php         ← ZIP in PHP puro (usato da XlsxWriter)
├── sql/
│   └── schema.sql                 ← DDL tabelle di log (eseguire una volta)
└── .gitignore
```

---

## Comandi disponibili

### Esecuzione reale — estrae i dati e carica su CKAN

```bash
# Produzione (default se --env omesso)
php bin/scheduler.php --env=production

# Test
php bin/scheduler.php --env=test

# Con log su file (consigliato per cron)
php bin/scheduler.php --env=production 2>&1 | tee /tmp/scheduler_$(date +%Y%m%d_%H%M%S).txt
```

### Dry-run — simula senza toccare CKAN né il DB di log

```bash
# Default: usa env "test"
php bin/dry-run.php

# Esplicito su test
php bin/dry-run.php --env=test

# Dry-run sui dati di produzione (solo lettura DB, nessuna scrittura CKAN)
php bin/dry-run.php --env=production
```

Il dry-run stampa per ogni risorsa un box con:
- `resource_id` CKAN di destinazione
- `database_origin` (schema PostgreSQL sorgente)
- path locale del file generato
- dimensione e checksum MD5
- endpoint che verrebbe chiamato

### Selezione ambiente

| Metodo | Esempio | Priorità |
|--------|---------|----------|
| Flag CLI | `--env=production` | Massima |
| Variabile d'ambiente | `APP_ENV=production php bin/scheduler.php` | Media |
| Default scheduler | `production` | Bassa |
| Default dry-run | `test` | Bassa |

### Exit codes

| Codice | Significato |
|--------|-------------|
| `0` | Successo completo |
| `1` | Uno o più errori parziali (dettagli in `scheduler_error_log`) |
| `2` | File `.ini` mancante o chiave obbligatoria assente |
| `3` | Lock non acquisibile (altra istanza in esecuzione) |

---

## Configurazione

### `config/common.ini` — parametri condivisi (committare in VCS)

```ini
[retry]
max_attempts   = 3        ; tentativi totali per ogni chiamata CKAN
base_delay_ms  = 500      ; attesa primo retry in millisecondi
multiplier     = 2.0      ; moltiplicatore backoff esponenziale
max_delay_ms   = 10000    ; attesa massima tra retry

[filesystem]
output_dir = BASE_DIR/output       ; BASE_DIR = path assoluto del progetto
lock_file  = BASE_DIR/run/scheduler.lock

[tables]
resource_log = opendata.resource_upload_log
error_log    = opendata.scheduler_error_log

[scheduling]
materialized_view = opendata.all_schedulation
query             = SELECT * FROM opendata.all_schedulation
```

### `config/env/production.ini` — credenziali produzione (NON committare)

```ini
[db]
host     = 127.0.0.1
port     = 5432
dbname   = opendata
user     = tuo_utente
password = tua_password

[ckan]
base_url = https://dati.toscana.it
api_key  = eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...   ; JWT token generato dal profilo CKAN

[filesystem]
output_dir = /percorso/assoluto/output
lock_file  = /percorso/assoluto/run/scheduler.lock

; ── Database alternativi per schema di origine ──────────────────────────────
; Stesse credenziali del [db] principale, cambia solo dbname.
; Il nome della sezione è db_<database_origin> come appare in all_schedulation.

[db_ambiente]
dbname = ambiente

[db_amm_viab]
dbname = amm_viab

[db_vp]
dbname = vp

; Aggiungi una sezione per ogni schema che usa un DB separato.
; Gli schemi non elencati usano automaticamente la connessione [db] principale.
```

### `config/env/test.ini` — credenziali test (NON committare)

```ini
[db]
host     = localhost
port     = 5432
dbname   = opendata_test
user     = tuo_utente
password = tua_password

[ckan]
base_url = https://staging.dati.toscana.it
api_key  = eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...

[retry]
; Retry più veloci in test
max_attempts   = 2
base_delay_ms  = 100
```

---

## Vista materializzata `opendata.all_schedulation`

Il processo legge da questa vista i record da elaborare. I campi utilizzati sono:

| Campo | Tipo | Descrizione |
|-------|------|-------------|
| `id` | INTEGER | PK della tabella `schedule_upload_jobs` di origine |
| `resource_id` | TEXT | UUID della risorsa su CKAN |
| `resource_name` | TEXT | Nome file senza estensione |
| `json_params` | JSONB | Query sorgente: `{"query": "SELECT * FROM schema.tabella"}` |
| `file_extension` | TEXT | `csv` · `json` · `rdf` · `geojson` · `xlsx` |
| `database_origin` | TEXT | Schema PostgreSQL di provenienza (`amm_viab`, `ambiente`, `vp`, …) |
| `schedulation` | INTEGER | Codice frequenza (vedi tabella sotto) |
| `is_schedulation_day` | BOOLEAN | `TRUE` se oggi è un giorno di elaborazione |

### Codici di frequenza (`schedulation`)

| Codice | Frequenza |
|--------|-----------|
| `1` | Giornaliera |
| `2` | Settimanale (lunedì) |
| `3` | Mensile (1° del mese) |
| `4` | Trimestrale (1° di gennaio, aprile, luglio, ottobre) |
| `5` | Semestrale (1° di gennaio e luglio) |
| `6` | Annuale (1° gennaio) |

Solo i record con `is_schedulation_day = TRUE` vengono elaborati ad ogni esecuzione.

---

## Formati di output supportati

### CSV (`file_extension = csv`)

```json
{ "query": "SELECT id, nome, valore FROM schema.tabella" }
```

Produce un file CSV con intestazione. Tutti i campi della query diventano colonne.

---

### JSON (`file_extension = json`)

```json
{ "query": "SELECT * FROM schema.tabella" }
```

Produce un array JSON di oggetti, uno per riga.

---

### GeoJSON (`file_extension = geojson`)

Il renderer supporta **due modalità**:

**Modalità A — coordinate come colonne `lat` / `lon`** (più comune con PostGIS):

```json
{
  "query": "SELECT id, ST_Y(ST_Transform(geom, 4326)) AS lat, ST_X(ST_Transform(geom, 4326)) AS lon, nome FROM schema.tabella"
}
```

Produce:
```json
{
  "type": "FeatureCollection",
  "features": [
    {
      "type": "Feature",
      "geometry": { "type": "Point", "coordinates": [11.028, 43.731] },
      "properties": { "id": "A01", "lat": 43.731, "lon": 11.028, "nome": "..." }
    }
  ]
}
```

> ⚠️ GeoJSON vuole le coordinate in ordine **[longitude, latitude]** — il renderer lo gestisce automaticamente.

**Modalità B — colonna `geometry` o `geom` con stringa GeoJSON**:

```json
{
  "query": "SELECT id, ST_AsGeoJSON(geom) AS geometry, nome FROM schema.tabella"
}
```

**Modalità C — nessuna geometria**: `"geometry": null` (Feature valida senza posizione).

---

### RDF (`file_extension = rdf`)

```json
{ "query": "SELECT id, titolo, descrizione FROM schema.tabella" }
```

Produce RDF/XML con triple Dublin Core, una per riga.

---

### XLSX (`file_extension = xlsx`)

```json
{ "query": "SELECT id, nome, valore FROM schema.tabella" }
```

Produce un file Excel (.xlsx) con un solo foglio, generato senza librerie esterne
(`src/Writer/XlsxWriter.php` + `src/Writer/ZipBuilder.php`, non serve né Composer né `ext-zip`).

- Il foglio prende il nome della risorsa (max 31 caratteri).
- Intestazione in grassetto, bloccata e con filtro automatico; larghezza colonne automatica.
- Numeri → celle numeriche; booleani → VERO/FALSO; `NULL` → cella vuota; resto → testo.
- Stringhe con zeri iniziali (CAP, codici ISTAT, …) e numeri con più di 15 cifre restano **testo**.
- Date e timestamp restano testo nel formato restituito da PostgreSQL (es. `2026-10-14 08:00:00`).
- Limiti Excel: 1.048.575 righe di dati e 16.384 colonne (oltre → errore `extraction`);
  le celle oltre 32.767 caratteri vengono troncate.
- Upload su CKAN con `format = XLSX` e mime type
  `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`.

---

## File di output su disco

I file sono organizzati per schema di origine:

```
output/
├── amm_viab/
│   └── civici.csv
├── ambiente/
│   ├── Valori_massimi_e_minimi_giornalieri.csv
│   └── Picchi_minimi_giornalieri_umidita.csv
└── vp/
    ├── Localizzazione_piante.geojson
    └── Alberi_pubblici.csv
```

I file vengono **sempre sovrascritti** ad ogni esecuzione — nessuno storage storico.

---

## Tabelle PostgreSQL di log

Eseguire `sql/schema.sql` una sola volta prima del primo avvio.

### `opendata.resource_upload_log`

Tiene traccia dell'ultimo upload riuscito per ogni risorsa. Una riga per `resource_id`, aggiornata (upsert) ad ogni upload completato con successo.

| Campo | Descrizione |
|-------|-------------|
| `resource_id` | UUID risorsa CKAN |
| `resource_name` | Nome della risorsa |
| `database_origin` | Schema PostgreSQL di origine |
| `path_file` | Path locale del file al momento dell'upload |
| `data_upload` | Timestamp dell'ultimo upload riuscito |
| `file_extension` | Formato: csv, json, rdf, geojson, xlsx |

### `opendata.scheduler_error_log`

Ogni errore catturato viene persistito qui con tutti i dettagli.

| Campo | Descrizione |
|-------|-------------|
| `occurred_at` | Timestamp dell'errore |
| `error_type` | `extraction` · `upload` · `filesystem` · `validation` |
| `resource_id` | UUID risorsa coinvolta (se disponibile) |
| `resource_name` | Nome risorsa |
| `message` | Messaggio di errore |
| `server_response` | Risposta raw dal server CKAN (per errori upload) |
| `http_status` | Codice HTTP CKAN (per errori upload) |
| `stack_trace` | Stack trace PHP completo |

### View di monitoraggio

```sql
-- Errori degli ultimi 7 giorni
SELECT * FROM opendata.recent_scheduler_errors;

-- Stato corrente di tutte le risorse con conteggio errori ultimi 30 giorni
SELECT * FROM opendata.resource_upload_status;
```

---

## Flusso di esecuzione

```
1. Acquisisce il file lock (impedisce esecuzioni parallele)
2. REFRESH MATERIALIZED VIEW opendata.all_schedulation
3. Legge i record con is_schedulation_day = TRUE
4. Per ogni record:
   a. Apre la connessione al DB corretto (database_origin)
   b. Esegue la query in json_params
   c. Scrive il file in output/<database_origin>/<resource_name>.<ext>
5. Per ogni file estratto con successo:
   a. POST /api/3/action/resource_update su CKAN (con retry)
   b. Upsert su opendata.resource_upload_log
6. Tutti gli errori → opendata.scheduler_error_log
7. Rilascia il lock
```

---

## Installazione

```bash
# 1. Crea i file .ini con le credenziali partendo dai modelli (non vanno mai nel repository)
cp config/env/production.ini.example config/env/production.ini
cp config/env/test.ini.example config/env/test.ini
chmod 600 config/env/*.ini
nano config/env/production.ini
nano config/env/test.ini

# 2. Crea le tabelle di log (una sola volta)
psql -d opendata -f sql/schema.sql

# 3. Rendi eseguibili i binari
chmod +x bin/scheduler.php bin/dry-run.php

# 4. Testa con dry-run prima del primo upload reale
php bin/dry-run.php --env=production

# 5. Prima esecuzione reale
php bin/scheduler.php --env=production
```

## Aggiunta al cron

```bash
crontab -e
```

```
# Ogni giorno alle 02:00
0 2 * * * /usr/bin/php /percorso/bin/scheduler.php --env=production >> /var/log/ckan-scheduler.log 2>&1
```

---

## Note su CKAN

- Usare sempre un **API token JWT** (generato da `https://dati.toscana.it/user/<username>/api-tokens`), non la vecchia API key UUID — le vecchie chiavi sono deprecate su CKAN 2.9+.
- Il server `dati.toscana.it` richiede **HTTP/1.1** — il client lo forza automaticamente (`CURLOPT_HTTP_VERSION_1_1`). Con HTTP/2 il server chiude la connessione senza rispondere.
- Gli errori HTTP 4xx (es. 403, 404) **non vengono ritentati** — sono errori client permanenti. Gli errori 5xx vengono ritentati secondo la configurazione `[retry]`.

## Parte di Prosit

Questo componente fa parte di **[Prosit](https://github.com/ComuneMontelupoFiorentino/prosit)**,
il Sistema Informativo Territoriale del Comune di Montelupo Fiorentino. Nel repository
[prosit](https://github.com/ComuneMontelupoFiorentino/prosit) ci sono l'architettura, l'elenco
di tutti i componenti (topic [`prosit`](https://github.com/search?q=org%3AComuneMontelupoFiorentino+topic%3Aprosit&type=repositories))
e le versioni che funzionano insieme.
