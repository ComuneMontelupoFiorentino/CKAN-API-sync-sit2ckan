<?php

namespace Classes\Tasks;

use Classes\Services\Logger\MonthlyLogger;

class ResourceSearchTask
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
     * Esegue la ricerca della risorsa online
     * @param string $type 'datastore' o 'resource'
     * @param string|null $resourceId ID della risorsa (necessario se type=resource)
     * @param array $filters Filtri opzionali da applicare (solo datastore)
     * @param int $limit Numero record da recuperare (solo datastore)
     * @return array
     */
    public function execute(
        string $type,
        ?string $resourceId = null,
        array $filters = [],
        int $limit = 100
    ): array {
        if ($type === 'datastore') {
            $resourceId = $this->ckanConfig['resource_module'] ?? null;
            if (!$resourceId) {
                throw new \RuntimeException("resource_module non definito per ambiente datastore");
            }

            $payload = [
                'resource_id' => $resourceId,
                'limit'       => $limit,
                'sort'        => '_id desc'
            ];
            if (!empty($filters)) {
                $payload['filters'] = $filters;
            }

            $url = "{$this->ckanConfig['url']}/api/3/action/datastore_search";
            $method = 'POST';
        } elseif ($type === 'resource') {
            if (!$resourceId) {
                throw new \RuntimeException("resource_id richiesto per tipo resource");
            }
            $url = "{$this->ckanConfig['url']}/api/3/action/resource_show?id={$resourceId}";
            $method = 'GET';
            $payload = null;
        } else {
            throw new \RuntimeException("Tipo non valido: {$type}");
        }

        $ch = curl_init($url);
        $options = [
            CURLOPT_HTTPHEADER => [
                "Authorization: {$this->ckanConfig['key']}",
                "Content-Type: application/json"
            ],
            CURLOPT_RETURNTRANSFER => true
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($payload);
        }

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            $this->logger->log('resource_search_error', "Errore cURL ResourceSearch: ".curl_error($ch));
            return [];
        }

        $data = json_decode($response, true);
        if ($type === 'datastore') {
            $records = $data['result'] ?? [];
        } else { // resource normale
            $records = $data['result'] ?? [];
        }

        $this->logger->log('resource_search', "Tipo: {$type}, Recuperati record/risorsa. Status HTTP: {$status}");

        // Salvataggio su file leggibile
        $date = date('Y-m-d_H-i-s');
        $dir = __DIR__ . '/../../Data/ResourceOnline';
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        $filename = "{$dir}/resource_{$date}.json";
        file_put_contents($filename, json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->logger->log('resource_search', "Risultato salvato su file: {$filename}");

        return $records;
    }
}
