<?php

namespace Classes\Tasks;

use Classes\Services\Logger\MonthlyLogger;

class RecordUpdateTask
{
    private array $ckanConfig;
    private MonthlyLogger $logger;

    public function __construct(
        string $env,
        MonthlyLogger $logger
    ) {
        $this->logger = $logger;
        $this->ckanConfig = $this->loadCkanConfig($env);
    }

    /**
     * Carica la configurazione CKAN per ambiente
     */
    public function loadCkanConfig(string $env): array
    {
        $file = __DIR__ . '/../../config/ckan_client_config.ini';

        if (!file_exists($file)) {
            throw new \RuntimeException("ckan_client_config.ini non trovato");
        }

        $data = parse_ini_file($file, true);

        if (!isset($data[$env])) {
            throw new \RuntimeException("Ambiente CKAN '{$env}' non definito");
        }

        return $data[$env];
    }

    public function execute(array $record): bool
    {
        $payload = [
            'resource_id' => $this->ckanConfig['resource_module'],
            'force'       => true,
            'records'     => [
                [
                    'id'                  => $record['id_ckan'] ?? $record['id'],
                    'record_id'           => $record['record_id'],
                    'module_id'           => $record['module_id'],
                    'module_name'         => $record['module_name'],
                    'data_invio'          => $record['date_send_module'],
                    'campi'               => $record['fields'],
                    'db_integration'      => $record['db_integration'] ?? true,
                    'date_db_integration' => date('c')
                ]
            ]
        ];

        $ch = curl_init("{$this->ckanConfig['url']}/api/3/action/datastore_upsert");
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
            $this->logger->log(
                'record_update_error',
                'Errore cURL RecordUpdate: ' . curl_error($ch)
            );
            curl_close($ch);
            return false;
        }

        curl_close($ch);

        $this->logger->log(
            'record_update',
            'Record aggiornato su CKAN: ' . json_encode($payload['records'][0])
        );

        return true;
    }
}
