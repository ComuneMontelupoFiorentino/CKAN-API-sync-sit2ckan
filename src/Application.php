<?php

declare(strict_types=1);

namespace CkanScheduler;

use CkanScheduler\Config\Config;
use CkanScheduler\Database\Connection;
use CkanScheduler\Exception\CkanApiException;
use CkanScheduler\Exception\ExtractionException;
use CkanScheduler\Exception\FilesystemException;
use CkanScheduler\Http\RetryHttpClient;
use CkanScheduler\Lock\FileLock;
use CkanScheduler\Logging\ErrorLogger;
use CkanScheduler\Model\SchedulationRecord;
use CkanScheduler\Repository\ResourceLogRepository;
use CkanScheduler\Service\CkanUploader;
use CkanScheduler\Service\DataExtractor;
use CkanScheduler\Service\DryRunUploader;
use CkanScheduler\Service\UploaderInterface;
use CkanScheduler\Database\ConnectionPool;

/**
 * Orchestratore principale.
 *
 * Fasi di ogni esecuzione:
 *  1. Acquisisce il file lock (anti-sovrapposto)
 *  2. Esegue REFRESH MATERIALIZED VIEW
 *  3. Legge all_schedulation e filtra is_schedulation_day = TRUE
 *  4. Per ogni record: esegue la query sorgente e scrive il file su disco
 *  5. Per ogni record estratto con successo: carica il file su CKAN
 *  6. Aggiorna resource_upload_log su PostgreSQL
 *  7. Rilascia il lock
 */
final class Application
{
    private Connection            $db;
    private ConnectionPool        $pool;
    private ErrorLogger           $logger;
    private DataExtractor         $extractor;
    private UploaderInterface     $uploader;
    private ResourceLogRepository $logRepo;
    private bool                  $dryRun;

    public function __construct(
        private readonly Config $config,
        bool $dryRun = false,
    ) {
        $this->dryRun    = $dryRun;
        $this->db        = new Connection($config);
        $this->pool      = new ConnectionPool($config);
        $this->logger    = new ErrorLogger($this->db, $config);
        $this->extractor = new DataExtractor($this->pool, $config->outputDir());
        $this->logRepo   = new ResourceLogRepository($this->db, $config);

        if ($dryRun) {
            $this->uploader = new DryRunUploader();
        } else {
            $this->uploader = new CkanUploader(new RetryHttpClient($config), $config);
        }
    }

    // ── Metodi pubblici ───────────────────────────────────────────────────────

    /**
     * Esecuzione completa schedulata.
     *
     * @return int  Exit code: 0 = successo, 1 = uno o più errori parziali.
     */
    public function run(): int
    {
        // ── 1. Lock ───────────────────────────────────────────────────────────
        $lock = new FileLock($this->config->lockFile());
        $lock->acquire();

        $mode = $this->dryRun ? ' [DRY-RUN — nessun upload su CKAN]' : '';
        $this->info("=== CKAN Scheduler avviato | env={$this->config->env()}{$mode} ===");

        // ── 2. Refresh vista materializzata ───────────────────────────────────
        $this->refreshMaterializedView();

        // ── 3. Carica i record pianificati per oggi ───────────────────────────
        $records = $this->loadScheduledRecords();

        if (empty($records)) {
            $this->info("Nessun record pianificato per oggi. Uscita.");
            $lock->release();
            return 0;
        }

        $this->info(sprintf("%d record/i da elaborare.", count($records)));

        // ── 4. Estrazione dati → file ─────────────────────────────────────────
        /** @var array<string,string> $filePaths  resource_id => path locale */
        $filePaths = [];
        $extractFail = 0;

        foreach ($records as $record) {
            try {
                $path = $this->extractor->extract($record);
                $filePaths[$record->resourceId] = $path;

                $this->info(sprintf(
                    "  [EXTRACT OK] %s (%s) → %s",
                    $record->resourceName,
                    $record->databaseOrigin,
                    $path,
                ));
            } catch (ExtractionException | FilesystemException $e) {
                $this->logger->logExtraction(
                    $e->getMessage(),
                    [
                        'resource_id'   => $record->resourceId,
                        'resource_name' => $record->resourceName,
                    ],
                    $e,
                );
                $extractFail++;
                $this->warn("  [EXTRACT FAIL] {$record->resourceName}: {$e->getMessage()}");
            }
        }

        // ── 5 & 6. Upload su CKAN + aggiornamento log ────────────────────────
        $uploadOk   = 0;
        $uploadFail = 0;

        foreach ($records as $record) {
            $path = $filePaths[$record->resourceId] ?? null;
            if ($path === null) {
                continue;
            }

            [$ok, $exception] = $this->uploadRecord($record, $path);

            if ($ok) {
                $uploadOk++;
            } else {
                $uploadFail++;
                $this->logger->log(
                    'upload',
                    $exception->getMessage(),
                    [
                        'resource_id'     => $record->resourceId,
                        'resource_name'   => $record->resourceName,
                        'server_response' => $exception->responseBody,
                        'http_status'     => $exception->httpStatus,
                    ],
                    $exception,
                );
                $this->warn("  [UPLOAD FAIL] {$record->resourceName}: {$exception->getMessage()}");
            }
        }

        // ── Riepilogo ─────────────────────────────────────────────────────────
        if ($this->dryRun && $this->uploader instanceof DryRunUploader) {
            $this->uploader->printSummary();
        }

        $elapsed = round(microtime(true) - START_TIME, 2);
        $this->info(sprintf(
            "=== Completato in %.2fs | Upload: %d OK, %d FAIL | Estrazioni fallite: %d ===",
            $elapsed, $uploadOk, $uploadFail, $extractFail,
        ));

        $lock->release();
        // prima le estrazioni fallite (query errata, cartella non scrivibile) non
        // cambiavano il codice di uscita: "0 OK, 0 FAIL" ed exit 0 anche con 28 errori
        return ($uploadFail > 0 || $extractFail > 0) ? 1 : 0;
    }

    /**
     * Esegue l'upload di una sola risorsa, identificata per resource_id o resource_name.
     * Non acquisisce il lock — pensato per uso manuale da CLI.
     *
     * @return int  Exit code: 0 = successo, 1 = errore.
     */
    public function runSingle(?string $resourceId = null, ?string $resourceName = null): int
    {
        $this->info("=== CKAN Scheduler [SINGLE] avviato | env={$this->config->env()} ===");

        $this->refreshMaterializedView();

        // Carica tutti i record e filtra quello cercato
        $target = null;
        foreach ($this->db->iterate($this->config->schedulingQuery()) as $row) {
            $matchId   = $resourceId   !== null && ($row['resource_id']   ?? '') === $resourceId;
            $matchName = $resourceName !== null && stripos($row['resource_name'] ?? '', $resourceName) !== false;

            if ($matchId || $matchName) {
                try {
                    $target = SchedulationRecord::fromRow($row);
                } catch (\InvalidArgumentException $e) {
                    fwrite(STDERR, "[FATAL] Record non valido: " . $e->getMessage() . "\n");
                    return 1;
                }
                break;
            }
        }

        if ($target === null) {
            fwrite(STDERR, "[FATAL] Risorsa non trovata: " . ($resourceId ?? $resourceName) . "\n");
            return 1;
        }

        $this->info(sprintf(
            "Risorsa trovata: %s (resource_id=%s, origin=%s)",
            $target->resourceName,
            $target->resourceId,
            $target->databaseOrigin,
        ));

        // Estrazione
        try {
            $path = $this->extractor->extract($target);
            $this->info("  [EXTRACT OK] → {$path}");
        } catch (\Throwable $e) {
            fwrite(STDERR, "[FATAL] Estrazione fallita: " . $e->getMessage() . "\n");
            return 1;
        }

        // Upload
        [$ok, $exception] = $this->uploadRecord($target, $path);

        if (!$ok) {
            fwrite(STDERR, "[FATAL] Upload fallito: " . $exception->getMessage() . "\n");
            return 1;
        }

        $elapsed = round(microtime(true) - START_TIME, 2);
        $this->info(sprintf("=== Completato in %.2fs ===", $elapsed));
        return 0;
    }

    // ── Helper privati ────────────────────────────────────────────────────────

    /**
     * Esegue upload + log + clearIsFirst per un singolo record.
     * Gestisce il 500 come successo (bug noto CKAN datastore).
     *
     * @return array{bool, CkanApiException|null}
     *         [true, null]       → upload riuscito (o 500 ignorato)
     *         [false, exception] → errore reale (4xx, timeout, ecc.)
     */
    private function uploadRecord(SchedulationRecord $record, string $path): array
    {
        try {
            $this->uploader->uploadResource($record, $path);
            $this->handleUploadSuccess($record, $path);
            $this->info(sprintf(
                "  [UPLOAD OK] %s (resource_id=%s)",
                $record->resourceName,
                $record->resourceId,
            ));
            return [true, null];

        } catch (CkanApiException $e) {
            // Il server CKAN restituisce 500 anche quando l'upload
            // è andato a buon fine (bug noto del datastore).
            // Trattiamo il 500 come successo.
            if ($e->httpStatus === 500) {
                $this->handleUploadSuccess($record, $path);
                $this->info(sprintf(
                    "  [UPLOAD OK - 500 ignorato] %s (resource_id=%s)",
                    $record->resourceName,
                    $record->resourceId,
                ));
                return [true, null];
            }

            // Errore reale (403, 404, timeout, ecc.)
            return [false, $e];
        }
    }

    /**
     * Aggiorna il log di upload e resetta is_first dopo un upload riuscito
     * (incluso il caso 500 ignorato).
     * Non fa nulla in modalità dry-run.
     */
    private function handleUploadSuccess(SchedulationRecord $record, string $path): void
    {
        if ($this->dryRun) {
            return;
        }

        $this->logRepo->upsert($record, $path);

        if ($record->isFirst) {
            $db = $this->pool->forOrigin($record->databaseOrigin);
            $this->logRepo->clearIsFirst($record, $db);
            $this->info(sprintf(
                "  [IS_FIRST RESET] %s → is_first=false",
                $record->resourceName,
            ));
        }
    }

    private function refreshMaterializedView(): void
    {
        $view = $this->config->materializedView();
        $this->info("Refresh vista materializzata {$view}…");

        try {
            $this->db->exec("REFRESH MATERIALIZED VIEW {$view}");
        } catch (\Throwable $e) {
            $this->logger->log(
                'extraction',
                "REFRESH MATERIALIZED VIEW fallito: " . $e->getMessage(),
                [],
                $e,
            );
            $this->warn("Refresh fallito (si procede con i dati esistenti): " . $e->getMessage());
        }
    }

    /** @return SchedulationRecord[] */
    private function loadScheduledRecords(): array
    {
        $records = [];

        foreach ($this->db->iterate($this->config->schedulingQuery()) as $row) {
            $isDay = $row['is_schedulation_day'];
            if ($isDay === false || $isDay === 'f' || $isDay === '0' || $isDay === null) {
                continue;
            }

            try {
                $records[] = SchedulationRecord::fromRow($row);
            } catch (\InvalidArgumentException $e) {
                $this->logger->logValidation(
                    $e->getMessage(),
                    ['resource_id' => $row['resource_id'] ?? null],
                    $e,
                );
                $this->warn("  [VALIDATION SKIP] " . $e->getMessage());
            }
        }

        return $records;
    }

    private function info(string $msg): void
    {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    }

    private function warn(string $msg): void
    {
        fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] WARN ' . $msg . PHP_EOL);
    }
}