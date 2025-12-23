<?php

namespace Classes\Tasks;

use Classes\Services\Logger\MonthlyLogger;

class RecordsGetTask
{
    private array $ckanConfig;
    private MonthlyLogger $logger;

    public function __construct(string $env, MonthlyLogger $logger)
    {
        $this->logger = $logger;
        $this->ckanConfig = $this->loadCkanConfig($env);
    }

    /**
     * Carica configurazione CKAN per ambiente
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

    /**
     * Esegue la chiamata Records GET
     * @param array $filters Associativo: ['record_id'=>'MAN-99', 'module_id'=>'XYZ']
     * @return array
     */
    public function execute(array $filters = []): array
    {
        $payload = [
            'resource_id' => $this->ckanConfig['resource_module'],
            'filters'     => $filters
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
            $this->logger->log('records_get_error', 'Errore cURL RecordsGet: ' . curl_error($ch));
            curl_close($ch);
            return [];
        }

        curl_close($ch);

        $data = json_decode($response, true);
        $records = $data['result']['records'] ?? [];

        $this->logger->log('records_get', "Recuperati " . count($records) . " record");

        // Salvataggio su file leggibile
        $date = date('Y-m-d_H-i-s');
        $filename = __DIR__ . "/../../Data/RecordsSourceOnline/records_{$date}.json";
        file_put_contents($filename, json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->logger->log('records_get', "Records salvati su file: {$filename}");

        return $records;
    }
}
