<?php

namespace Classes\Tasks;

use Classes\Services\Database\PostgresConnection;
use Classes\Services\Logger\MonthlyLogger;
use PDO;
use Exception;

class SyncRecordsTask
{
    private array $ckanConfig;
    private RecordUpdateTask $recordUpdateTask;

    public function __construct(
        private PostgresConnection $db,
        MonthlyLogger $logger,
        string $env
    ) {
        $this->logger = $logger;
    
        $this->recordUpdateTask = new RecordUpdateTask($env, $logger);
        $this->ckanConfig = $this->recordUpdateTask->loadCkanConfig($env);
    }

    private function recordsGet(): array
    {
        $payload = [
            'resource_id' => $this->ckanConfig['resource_module'],
            'filters'     => ['db_integration' => 'false']
        ];

        $ch = curl_init("{$this->ckanConfig['url']}/api/3/action/datastore_search");
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                "Authorization: {$this->ckanConfig['key']}",
                "Content-Type: application/json"
            ],
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true
        ]);

        $response = curl_exec($ch);

        if ($response === false) {
            $this->logger->log('sync_error', 'Errore cURL RecordsGet: ' . curl_error($ch));
            curl_close($ch);
            return [];
        }

        curl_close($ch);
        $data = json_decode($response, true);

        return $data['result']['records'] ?? [];
    }

    private function extractDateSendModule(array $rec): string
    {
        // Ordine di priorità dei campi possibili
        $possibleKeys = [
            'date_send_module',
            'data_invio',
            'date_send',
            'data_send'
        ];

        foreach ($possibleKeys as $key) {
            if (!empty($rec[$key])) {
                return date('Y-m-d', strtotime($rec[$key]));
            }
        }

        // fallback sicuro
        return date('Y-m-d');
    }

    public function run(): void
    {
        $records = $this->recordsGet();

        if (empty($records)) {
            $this->logger->log('sync', 'Nessun record da sincronizzare.');
            return;
        }

        $pdo = $this->db->getPdo();

        foreach ($records as $rec) {
            try {
                $stmt = $pdo->prepare(
                    "INSERT INTO llpp.module_online
                    (id_ckan, record_id, module_id, module_name, date_send_module, fields)
                    VALUES (:id_ckan, :record_id, :module_id, :module_name, :date_send_module, :fields)
                    RETURNING id"
                );

                $stmt->execute([
                    ':id_ckan'          => $rec['id'] ?? 0,
                    ':record_id'        => $rec['record_id'] ?? '',
                    ':module_id'        => $rec['module_id'] ?? '',
                    ':module_name'      => $rec['module_name'] ?? '',
                    ':date_send_module' => $this->extractDateSendModule($rec),
                    ':fields'           => json_encode($rec['campi'] ?? [])
                ]);

                $idInserted = $stmt->fetchColumn();
                $this->logger->log('sync', "Record inserito in PostgreSQL ID={$idInserted}");

                $stmt2 = $pdo->prepare(
                    "SELECT * FROM llpp.module_online WHERE id = :id"
                );
                $stmt2->execute([':id' => $idInserted]);
                $pgRecord = $stmt2->fetch(PDO::FETCH_ASSOC);

                if ($pgRecord) {
                    $this->recordUpdateTask->execute($pgRecord);
                }

            } catch (Exception $e) {
                $this->logger->log(
                    'sync_error',
                    'Errore sincronizzazione record: ' . $e->getMessage()
                );
            }
        }
    }
}
