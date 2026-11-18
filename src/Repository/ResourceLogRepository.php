<?php

declare(strict_types=1);

namespace CkanScheduler\Repository;

use CkanScheduler\Config\Config;
use CkanScheduler\Database\Connection;
use CkanScheduler\Model\SchedulationRecord;

/**
 * Persiste e aggiorna il log degli upload sul DB PostgreSQL.
 *
 * DDL atteso (eseguire una volta con sql/schema.sql):
 *
 *   CREATE TABLE IF NOT EXISTS opendata.resource_upload_log (
 *       id              BIGSERIAL    PRIMARY KEY,
 *       resource_id     TEXT         NOT NULL,
 *       resource_name   TEXT         NOT NULL,
 *       database_origin TEXT         NOT NULL,
 *       path_file       TEXT         NOT NULL,
 *       data_upload     TIMESTAMPTZ  NOT NULL DEFAULT now(),
 *       file_extension  TEXT         NOT NULL,
 *       UNIQUE (resource_id)
 *   );
 *
 * Nota: dataset_id e download_block rimossi perché non presenti
 * nella vista reale opendata.all_schedulation.
 */
final class ResourceLogRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly Config     $config,
    ) {}

    /**
     * Upsert del record di upload.
     * Se resource_id esiste già, aggiorna path, timestamp ed estensione
     * (riflette sempre lo stato corrente su CKAN).
     */
    public function upsert(SchedulationRecord $record, string $localPath): void
    {
        $table = $this->config->tableResourceLog();

        $this->db->execute(
            "INSERT INTO {$table}
                (resource_id, resource_name, database_origin, path_file, data_upload, file_extension)
             VALUES
                (:resource_id, :resource_name, :database_origin, :path_file, now(), :file_extension)
             ON CONFLICT (resource_id) DO UPDATE SET
                resource_name   = EXCLUDED.resource_name,
                database_origin = EXCLUDED.database_origin,
                path_file       = EXCLUDED.path_file,
                data_upload     = now(),
                file_extension  = EXCLUDED.file_extension",
            [
                ':resource_id'     => $record->resourceId,
                ':resource_name'   => $record->resourceName,
                ':database_origin' => $record->databaseOrigin,
                ':path_file'       => $localPath,
                ':file_extension'  => $record->fileExtension->value,
            ],
        );
    }
    /**
     * Dopo il primo upload riuscito, disattiva il flag is_first
     * sulla tabella schedule_upload_jobs dello schema di origine.
     * Usa la connessione al DB corretto (database_origin).
     */
    public function clearIsFirst(SchedulationRecord $record, Connection $originDb): void
    {
        if (!$record->isFirst) {
            return;
        }

        $affected = $originDb->execute(
            "UPDATE opendata.schedule_upload_jobs
            SET is_first = false
            WHERE resource_id = :resource_id",
            [':resource_id' => $record->resourceId]
        );

        if ($affected === 0) {
            throw new \RuntimeException(
                "clearIsFirst: nessuna riga aggiornata per resource_id '{$record->resourceId}' " .
                "in {$record->databaseOrigin}.opendata.schedule_upload_jobs. " .
                "Verifica che il campo resource_id esista nella tabella sorgente."
            );
        }
    }
}
