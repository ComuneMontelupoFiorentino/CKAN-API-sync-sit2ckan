<?php

require_once __DIR__ . '/bootstrap.php';

use Classes\Services\Database\PostgresConnection;
use Classes\Services\Logger\MonthlyLogger;
use Classes\Tasks\SyncRecordsTask;
use Classes\Tasks\RecordUpdateTask;
use Classes\Tasks\RecordsGetTask;
use Classes\Tasks\UploadFileTask;
use Classes\Tasks\ResourceSearchTask;
use Classes\Services\Opendata\SchedulationLoader;
use Classes\Services\Opendata\Export\ExportDataWriter;

/**
 * ============================
 * Parsing CLI
 * ============================
 */
$args = getopt('', [
    'env:',                 // Tutti i comandi
    'id_ckan:',             // RECORD UPDATE
    'record_id:',           // RECORD UPDATE e RECORDS GET
    'module_id:',           // RECORD UPDATE e RECORDS GET
    'module_name:',         // RECORD UPDATE e RECORDS GET
    'date_send_module:',    // RECORD UPDATE
    'fields:',              // RECORD UPDATE
    'db_integration:',      // RECORD UPDATE
    'resource_id:',         // UPLOAD FILE & RESOURCE SEARCH (opzionale)
    'file_path:',           // UPLOAD FILE
    'type:',                // RESOURCE SEARCH (datastore o resource)
    'filters:',             // RESOURCE SEARCH (opzionale, solo per datastore)
    'limit:',               // RESOURCE SEARCH (opzionale, solo per datastore)
    'schedulation_id:'      // EXPORT DATA (opzionale)
]);

$envFlag = $argv[1] ?? null;
$command = $argv[2] ?? null;

if (!$command || !$envFlag) {
    echo "Error: You must define env and command.";
    exit(1);
}

$schedulationId = isset($args['schedulation_id'])
    ? (int)$args['schedulation_id']
    : null;

/**
 * ============================
 * Ambiente
 * ============================
 */
switch ($envFlag) {
    case '-test':
        $pgEnv   = 'pg_test';
        $ckanEnv = 'ckan_test';
        break;
    case '-prod':
        $pgEnv   = 'pg_prod';
        $ckanEnv = 'ckan_prod';
        break;
    default:
        echo "Error: invalid env (-test|-prod)\n";
        exit(1);
}

$ckanConfigFile = __DIR__ . '/config/ckan_client_config.ini';
$ckanConfigs = parse_ini_file($ckanConfigFile, true);

if (!isset($ckanConfigs[$ckanEnv]['resource_local_path'])) {
    throw new Exception("resource_local_path non definito in {$ckanEnv}");
}

$resourceLocalPath = rtrim(
    $ckanConfigs[$ckanEnv]['resource_local_path'],
    '/'
);

// Logger
$logger = new MonthlyLogger(__DIR__ . '/Logs');

/**
 * ============================
 * SWITCH COMANDI
 * ============================
 */
try {

    switch ($command) {

        /**
         * ========================
         * SYNC RECORDS
         * ========================
         */
        case '-SR':

            $db = new PostgresConnection(
                $pgEnv,
                __DIR__ . '/config/pg_service.conf',
                $logger
            );

            echo "Connessione PostgreSQL OK ({$pgEnv})\n";

            $task = new SyncRecordsTask($db, $logger, $ckanEnv);
            $task->run();

            echo "SyncRecordsTask completato\n";
            break;

        /**
         * ========================
         * RECORD UPDATE
         * ========================
         */
        case '-RU':

            $required = [
                'id_ckan',
                'record_id',
                'module_id',
                'module_name'
            ];

            foreach ($required as $req) {
                if (empty($args[$req])) {
                    throw new Exception("Parametro mancante: --{$req}");
                }
            }

            $fields = $args['fields'] ?? '{}';
            $fieldsArray = json_decode($fields, true);

            // Se il decode fallisce, fallback a array vuoto
            if (!is_array($fieldsArray)) {
                $fieldsArray = [];
            }

            $record = [
                'id_ckan'          => (int)$args['id_ckan'],
                'record_id'        => $args['record_id'],
                'module_id'        => $args['module_id'],
                'module_name'      => $args['module_name'],
                'date_send_module' => $args['date_send_module'] ?? date('Y-m-d'),
                'fields'           => [$fieldsArray],
                'db_integration'   => filter_var($args['db_integration'] ?? false, FILTER_VALIDATE_BOOLEAN)
            ];

            $task = new RecordUpdateTask($ckanEnv, $logger);
            $task->execute($record);

            echo "RecordUpdateTask completato\n";
            break;

        /**
         * ========================
         * RECORDS GET
         * ========================
         */

        case '-RG':
            $filters = [];
            foreach (['record_id','module_id','module_name'] as $key) {
                if (!empty($args[$key])) {
                    $filters[$key] = $args[$key];
                }
            }

            $task = new RecordsGetTask($ckanEnv, $logger);
            $records = $task->execute($filters);
            
            echo "Recuperati " . count($records) . " record.\n";
            echo "Dettagli salvati nel log.\n";
            break;

        /**
         * ========================
         * UPLOAD FILE
         * ========================
         */
        case '-UF': // Upload File
            $required = ['resource_id', 'file_path'];
            foreach ($required as $req) {
                if (empty($args[$req])) {
                    throw new Exception("Parametro mancante: --{$req}");
                }
            }
    
            $resourceId = $args['resource_id'];
            $filePath = $args['file_path'];
    
            $task = new UploadFileTask($ckanEnv, $logger);
            $success = $task->execute($resourceId, $filePath);
    
            echo $success ? "UploadFileTask completato\n" : "Errore durante UploadFileTask\n";
            break;

        /**
         * ========================
         * RESOURCE SEARCH
         * ========================
         */
        case '-RS':
            $type = $args['type'] ?? null;
            if (!$type) {
                throw new Exception("Parametro mancante: --type (datastore|resource)");
            }
        
            $resourceId = $args['resource_id'] ?? null;
            $limit = isset($args['limit']) ? (int)$args['limit'] : 100;
        
            $filters = [];
            if (!empty($args['filters'])) {
                $filters = json_decode($args['filters'], true);
                if (!is_array($filters)) {
                    throw new Exception("Il parametro --filters deve essere una JSON string valida");
                }
            }
        
            $task = new ResourceSearchTask($ckanEnv, $logger);
            $records = $task->execute($type, $resourceId, $filters, $limit);
        
            echo "Recuperati record/risorsa. Dettagli salvati nel file JSON.\n";
            break; 

        /**
         * ========================
         * EXPORT DATA (OPENDATA)
         * ========================
         */
        case '-EXD':

            $db = new PostgresConnection(
                $pgEnv,
                __DIR__ . '/config/pg_service.conf',
                $logger
            );
        
            echo "Connessione PostgreSQL OK ({$pgEnv})\n";
        
            // Loader schedulazioni (con filtro opzionale)
            $loader = new SchedulationLoader($db, $schedulationId);
            $schedulations = $loader->getSchedulations();
        
            if (empty($schedulations)) {
                echo "Nessuna schedulazione trovata\n";
                break;
            }
        
            $logBaseDir = __DIR__ . '/Logs';
        
            foreach ($schedulations as $schedulation) {
        
                $writer = new ExportDataWriter(
                    $schedulation['resource_name'],
                    $schedulation['file_extension'],
                    $schedulation['fields'],
                    $schedulation['records'],
                    $resourceLocalPath,
                    $logBaseDir
                );
        
                $filePath = $writer->export();
                echo "Export completato: {$filePath}\n";
            }
        
            echo "ExportData completato per ambiente {$ckanEnv}\n";
            break;         

        default:
            throw new Exception("Comando non valido: {$command}");
    }

} catch (Throwable $e) {
    echo "ERRORE: {$e->getMessage()}\n";
    $logger->log('cli_error', $e->getMessage());
    exit(1);
}
