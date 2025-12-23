<?php

namespace Classes\Services\Database;

use PDO;
use PDOException;
use Classes\Services\Logger\MonthlyLogger;

class PostgresConnection
{
    private PDO $pdo;

    public function __construct(
        string $pgServiceName,
        string $pgServiceFile,
        MonthlyLogger $logger
    ) {
        try {
            $config = $this->loadPgService($pgServiceFile, $pgServiceName);

            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                $config['host'],
                $config['port'],
                $config['dbname']
            );

            $this->pdo = new PDO(
                $dsn,
                $config['user'],
                $config['password'],
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );

            // Test immediato della connessione
            $this->pdo->query('SELECT 1');

            $logger->log(
                'database',
                "Connessione PostgreSQL riuscita ({$pgServiceName})"
            );

        } catch (PDOException $e) {
            $logger->log(
                'database_error',
                "Errore connessione PostgreSQL ({$pgServiceName}): " . $e->getMessage()
            );
            throw $e;
        }
    }

    private function loadPgService(string $file, string $service): array
    {
        if (!file_exists($file)) {
            throw new \RuntimeException("pg_service.conf non trovato: {$file}");
        }

        $data = parse_ini_file($file, true);

        if (!isset($data[$service])) {
            throw new \RuntimeException("Servizio PostgreSQL '{$service}' non definito");
        }

        return $data[$service];
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }
}
