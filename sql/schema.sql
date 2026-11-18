-- ============================================================
-- DDL – Eseguire una sola volta prima del primo avvio.
-- Schema: opendata (deve già esistere)
-- ============================================================

-- ── Log upload risorse (Task 7) ───────────────────────────────────────────────
-- Una riga per resource_id (upsert ad ogni upload riuscito).
-- Riflette lo stato corrente delle risorse sul portale CKAN.
--
-- Campi allineati alla vista opendata.all_schedulation:
--   resource_id     → UUID risorsa CKAN
--   resource_name   → nome file (senza estensione)
--   database_origin → schema PostgreSQL di provenienza (amm_viab, ambiente, …)
--   path_file       → path assoluto locale al file al momento dell'ultimo upload
--   data_upload     → timestamp dell'ultimo upload riuscito
--   file_extension  → csv | json | rdf | geojson | xlsx
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS opendata.resource_upload_log (
    id              BIGSERIAL    PRIMARY KEY,
    resource_id     TEXT         NOT NULL,
    resource_name   TEXT         NOT NULL,
    database_origin TEXT         NOT NULL,
    path_file       TEXT         NOT NULL,
    data_upload     TIMESTAMPTZ  NOT NULL DEFAULT now(),
    file_extension  TEXT         NOT NULL,

    CONSTRAINT uq_resource_upload_log_resource_id UNIQUE (resource_id)
);

COMMENT ON TABLE  opendata.resource_upload_log                  IS 'Traccia l''ultimo upload riuscito di ogni risorsa CKAN.';
COMMENT ON COLUMN opendata.resource_upload_log.resource_id      IS 'UUID risorsa CKAN (coincide con schedule_upload_jobs.resource_id).';
COMMENT ON COLUMN opendata.resource_upload_log.resource_name    IS 'Nome della risorsa (senza estensione).';
COMMENT ON COLUMN opendata.resource_upload_log.database_origin  IS 'Schema PostgreSQL da cui provengono i dati (es. amm_viab, ambiente, …).';
COMMENT ON COLUMN opendata.resource_upload_log.path_file        IS 'Path assoluto al file locale al momento dell''ultimo upload.';
COMMENT ON COLUMN opendata.resource_upload_log.data_upload      IS 'Timestamp dell''ultimo upload completato con successo.';
COMMENT ON COLUMN opendata.resource_upload_log.file_extension   IS 'Formato del file: csv, json, rdf, geojson, xlsx.';

-- ── Log errori dello scheduler ────────────────────────────────────────────────
-- Ogni eccezione catturata durante estrazione, upload, filesystem o validazione
-- viene persistita qui con tutti i dettagli utili al debug.
--
-- Valori di error_type:
--   extraction  → query sorgente fallita o risultato non valido
--   upload      → errore chiamata API CKAN (include risposta HTTP)
--   filesystem  → impossibile scrivere file o creare directory
--   validation  → riga di all_schedulation con dati non validi (skip)
--   unknown     → eccezione non categorizzata
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS opendata.scheduler_error_log (
    id              BIGSERIAL    PRIMARY KEY,
    occurred_at     TIMESTAMPTZ  NOT NULL DEFAULT now(),
    error_type      TEXT         NOT NULL,
    resource_id     TEXT,
    resource_name   TEXT,
    message         TEXT         NOT NULL,
    server_response TEXT,
    http_status     INT,
    stack_trace     TEXT
);

COMMENT ON TABLE  opendata.scheduler_error_log                   IS 'Log strutturato degli errori del CKAN Scheduler.';
COMMENT ON COLUMN opendata.scheduler_error_log.error_type        IS 'Categoria: extraction | upload | filesystem | validation | unknown';
COMMENT ON COLUMN opendata.scheduler_error_log.server_response   IS 'Corpo della risposta HTTP CKAN (solo per errori di upload).';
COMMENT ON COLUMN opendata.scheduler_error_log.http_status       IS 'Codice HTTP restituito da CKAN (solo per errori di upload).';
COMMENT ON COLUMN opendata.scheduler_error_log.stack_trace       IS 'Stack trace PHP completo per il debug.';

-- ── Indici utili ──────────────────────────────────────────────────────────────
CREATE INDEX IF NOT EXISTS idx_error_log_occurred_at   ON opendata.scheduler_error_log (occurred_at DESC);
CREATE INDEX IF NOT EXISTS idx_error_log_resource_id   ON opendata.scheduler_error_log (resource_id);
CREATE INDEX IF NOT EXISTS idx_error_log_error_type    ON opendata.scheduler_error_log (error_type);
CREATE INDEX IF NOT EXISTS idx_resource_log_origin     ON opendata.resource_upload_log  (database_origin);
CREATE INDEX IF NOT EXISTS idx_resource_log_upload     ON opendata.resource_upload_log  (data_upload DESC);

-- ── View di monitoraggio: errori ultimi 7 giorni ──────────────────────────────
CREATE OR REPLACE VIEW opendata.recent_scheduler_errors AS
SELECT
    occurred_at,
    error_type,
    resource_id,
    resource_name,
    message,
    http_status,
    left(server_response, 500) AS server_response_excerpt
FROM opendata.scheduler_error_log
WHERE occurred_at >= now() - INTERVAL '7 days'
ORDER BY occurred_at DESC;

COMMENT ON VIEW opendata.recent_scheduler_errors
    IS 'Errori degli ultimi 7 giorni (server_response troncato a 500 caratteri).';

-- ── View di monitoraggio: stato corrente degli upload ─────────────────────────
CREATE OR REPLACE VIEW opendata.resource_upload_status AS
SELECT
    l.resource_id,
    l.resource_name,
    l.database_origin,
    l.file_extension,
    l.data_upload,
    l.path_file,
    -- Quante volte è stato loggato un errore per questa risorsa
    COUNT(e.id) FILTER (WHERE e.occurred_at >= now() - INTERVAL '30 days') AS errors_last_30d
FROM opendata.resource_upload_log l
LEFT JOIN opendata.scheduler_error_log e
       ON e.resource_id = l.resource_id
GROUP BY l.resource_id, l.resource_name, l.database_origin,
         l.file_extension, l.data_upload, l.path_file
ORDER BY l.database_origin, l.resource_name;

COMMENT ON VIEW opendata.resource_upload_status
    IS 'Stato corrente di tutte le risorse caricate, con conteggio errori ultimi 30 giorni.';
