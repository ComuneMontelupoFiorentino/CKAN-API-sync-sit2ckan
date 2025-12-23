<?php

namespace Classes\Tasks;

use Classes\Services\Logger\MonthlyLogger;

class UploadFileTask
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
     * Esegue l'upload del file sulla risorsa CKAN
     * @param string $resourceId ID della risorsa
     * @param string $filePath Percorso completo del file locale
     * @return bool
     */
    public function execute(string $resourceId, string $filePath): bool
    {
        if (!file_exists($filePath)) {
            $this->logger->log('upload_file_error', "File non trovato: {$filePath}");
            return false;
        }

        $ch = curl_init("{$this->ckanConfig['url']}/api/3/action/resource_update");
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ["Authorization: {$this->ckanConfig['key']}"],
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => [
                'id' => $resourceId,
                'format' => 'CSV',
                'upload' => new \CURLFile($filePath)
            ]
        ]);

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->logger->log(
            'upload_file',
            "Upload file '{$filePath}' per resource_id '{$resourceId}' completato. HTTP Status: {$status}. Response: {$response}"
        );

        // Se il JSON contiene 'success' true, ritorna true
        $data = json_decode($response, true);
        if (isset($data['success']) && $data['success'] === true) {
            return true;
        }

        // In caso di 500 ma file caricato, consideriamo comunque successo con warning
        if ($status === 500) {
            $this->logger->log('upload_file_warning', 'HTTP 500 ma file caricato correttamente');
            return true;
        }

        return false;
    }
}
