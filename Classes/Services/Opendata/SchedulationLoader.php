<?php

namespace Classes\Services\Opendata;

use Classes\Services\Database\PostgresConnection;
use PDO;
use RuntimeException;

class SchedulationLoader
{
    private PDO $pdo;
    private ?int $filterId;
    private array $schedulations = [];

    public function __construct(PostgresConnection $connection, ?int $filterId = null)
    {
        $this->pdo = $connection->getPdo();
        $this->filterId = $filterId;

        // Refresh solo se esecuzione "giornaliera"
        if ($this->filterId === null) {
            $this->refreshMaterializedView();
        }

        $this->loadSchedulations();
    }

    /* =======================
       REFRESH MATERIALIZED VIEW
       ======================= */
    private function refreshMaterializedView(): void
    {
        $this->pdo->exec(
            'REFRESH MATERIALIZED VIEW opendata.all_schedulation'
        );
    }

    /* =======================
       LOAD SCHEDULATIONS
       ======================= */
    private function loadSchedulations(): void
    {
        if ($this->filterId !== null) {
            $sql = '
                SELECT resource_name, file_extension, json_params
                FROM opendata.all_schedulation
                WHERE id = :id
            ';
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':id', $this->filterId, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $sql = '
                SELECT resource_name, file_extension, json_params
                FROM opendata.all_schedulation
                WHERE is_schedulation_day = true
            ';
            $stmt = $this->pdo->query($sql);
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return;
        }

        foreach ($rows as $row) {
            try {
                $this->schedulations[] = $this->buildSchedulation($row);
            } catch (\Throwable $e) {
                error_log(
                    '[SCHEDULATION ERROR] ' .
                    ($row['resource_name'] ?? 'unknown') .
                    ': ' . $e->getMessage()
                );
            }
        }
    }

    /* =======================
       BUILD SINGLE SCHEDULATION
       ======================= */
    private function buildSchedulation(array $row): array
    {
        if (empty($row['json_params'])) {
            throw new RuntimeException(
                'json_params mancante per resource ' . $row['resource_name']
            );
        }

        $params = json_decode($row['json_params'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(
                'json_params non valido per resource ' . $row['resource_name']
            );
        }

        if (empty($params['query'])) {
            throw new RuntimeException(
                'json_params: chiave "query" mancante per resource ' . $row['resource_name']
            );
        }

        $stmt = $this->pdo->query($params['query']);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $fields = !empty($records) ? array_keys($records[0]) : [];

        return [
            'resource_name'  => $row['resource_name'],
            'file_extension' => strtolower($row['file_extension']),
            'fields'         => $fields,
            'records'        => $records
        ];
    }

    /* =======================
       GETTER
       ======================= */
    public function getSchedulations(): array
    {
        return $this->schedulations;
    }
}
