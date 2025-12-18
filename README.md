# INTEGRAZIONE PORTALE CKAN E DBMS POSTGRES

La presente funzionalità è costituita da un insieme di script in linguaggio PHP in grado di gestire le operazioni di Upload di file, Ricerca di risorse, Query su risorse e gestione di Datastore (Upload record, Query, Upsert). Le operazioni svolte sono dettagliate in uno specifico file di log generato ad ogni processo.

**Keywords**: OPENDATA, API, INTEROPERABILITA, CKAN, PostgreSQL, Riuso, PA

## Requisiti di funzionamento

- Server con PHP versione `8` o superiore
- PHP deve essere compilato con le seguenti estensioni: `php-cli`, `php-curl`, `php-pgsql`, `openssl`
- database `Postgresql` versione 15 o superiore
- versione API del servizio CKAN 2.4

## Installazione

Lo script non necessita di alcuna installazione specifica, è sufficiente copiare il contenuto completo della cartella principale in una cartella di destinazione sul server sul quale si vorranno lanciare le funzionalità.

## Funzionamento generale dello script

Per ogni funzionalità, è possibile lanciare lo script in due modalità distinte, una modalità di `test` ed una modalità di `produzione`.

**Elenco delle funzionalità**

- `RecordUpdate`: Consente di aggiornare un record in un datastore
- `RecordsGET`: Consente di elencare i record della risorsa con o senza filtri
- `ExportData`: Consente di generare file da tabelle PostgreSQL nei formati CSV, JSON, GeoJSON, RDF
- `Uploadfile`: Consente l'Upload di file locali su risorsa online aggiornando quella presente
- `SyncRecords`: Consente di ottenere i records da un datastore e l'inserimento dei record in una tabella del DB PostgreSQL
- `ResourceSearch`: Consente di ottenere i metadati di una risorsa in un file jSON

### Struttura delle cartelle

```bash
|── cartella principale
    |── Classes/
        |── Services/
            |── Config/
                |── CkanConfigLoader.php
            |── Database/
                |── PostgresConnection.php
            |── Logger/
                |── MonthlyLogger.php
            |── Opendata/
                |── Export
                    |── ExportDataWriter.php
                |── SchedulationLoader.php
        |── Tasks/
            |── ExportDataWriter.php
                |── RecordsGetTask.php
                |── RecordUpdateTask.php
                |── ResourceSearchTask.php
                |── SyncRecordsTask.php
                |── UploadFileTask.php
    |── config/
        |── ckan_client_config.ini
        |── pg_service.conf
    |── Logs/
    |── bootstrap.php
    |── sync.php
```
## Configurazione

Configurare il file `config/pg_service.conf` con i parametri di connessione al db.
Il file può contenere due configurazioni `pg_test` e `pg_prod`. 
Questa distinzione è stata introdotta per garantire una maggiore flessibilità e test. Nulla vieta di impostare la stessa configurazione sia per test che per produzione.

Per i dettagli sulla struttura e sulla configurazione del file pg_service.conf consultare il [manuale](https://www.postgresql.org/docs/current/libpq-pgservice.html) Postgres dedicato.

Configurare il file `config/ckan_client_config.ini` con i parametri del servizio ckan.
Anche in questo caso è presente la distinzione tra ambiente di test e produzione per il servizio ckan.

```ìni
[ckan_test]
url=https://[PORTALE_CKAN].it
key=[TOKEN-O-API-KEY] 
resource_module=[ID-RISORSA-DATASTORE] 
; path locale dove ExportDataWriter salva i file
resource_local_path=/PERCORSO/AI/FILE/LOCALI/
```

## Utilizzo della funzionalità

Una volta completati gli step di configurazione precedenti, è possibile lanciare la funzionalità direttamente da riga di comando posizionandosi nella cartella principale (stesso livello del file `sync.php`)

```cli
$ php sync.php [ambiente] [comando] [filtro]
```

Per qualsiasi funzionalità è necessario definire ambiente di esecuzione e comando

### Ambiente

La definizione dell'ambiente di esecuzione è obbligatorio, quindi definire uno tra:

- `--test`, lancio in ambiente di test
- `--prod`, lancio in ambiente di produzione

in caso nessuna o entrambe le opzioni vengano specificate, lo script terminerà con errore.

### Comando

I comandi possono necessitare di filtri

| **FUNZIONALITA**              | **COMANDO**      | **PARAMETRO1**          | **TIPOLOGIA FILTRO** |
|-------------------------------|------------------|------------------------|----------------------|
| RecordUpdate                  | -RU              |                        |                      |
| RecordsGET                    | -RG              | --[campo]=[valore]     | Obbligatorio         |
| ExportData                    | -EXD             | --schedulation_id=[id] | Facoltativo          |
| Uploadfile                    | -UF              | --resource_id=[id]  --file_path=[/percorso/locale/al/file.csv] | Obbligatorio |
| SyncRecords                   | -SR              |                        |                      |
| ResourceSearch                | -RS              | -type=[datastore/resource] --limit=[numeroRecords] --filters='{"campo":"valore"}' --resource_id=[id] | Type obbligatorio, se `Datastore` allora --limit è obbligatorio e --filters facoltativo. Se resource allora --resource_id è obbligatorio. |

Esempi:

**RecordUpdate** 
```ìni
php sync.php -prod -SR
```
**RecordsGET**
```ìni
php sync.php -test -RG --id=99
```
**ExportData**
```ìni
php sync.php -prod -EXD --schedulation_id=2
```
**Uploadfile**
```ìni
php sync.php -prod -UF \
    			--resource_id=xxxxxxxxxxxxxxxx \
    			--file_path=/percorso/al/file/locale/file_locale.json
```
**SyncRecords**
```ìni
php sync.php -prod -SR
```
**ResourceSearch**
Risorsa
```ìni
php sync.php -prod -RS --type=resource --resource_id=xxxxxxxxxxxxxxxxxxx
```
Datastore
```ìni
php sync.php -test -RS --type=datastore --limit=100
```
Datastore con filtro
```ìni
php sync.php -test -RS --type=datastore --limit=10 --filters='{"iot": "stazione-meteo-comunale"}'
```

### Logs

Lo script ad ogni esecuzione aggiornerà un di file log nel percorso `/Logs/[anno]/[mese]/` denominato `{comando}.log`.
