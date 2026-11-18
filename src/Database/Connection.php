<?php

declare(strict_types=1);

namespace CkanScheduler\Database;

use CkanScheduler\Config\Config;
use PDO;
use PDOException;
use RuntimeException;

final class Connection
{
    private PDO $pdo;

    /**
     * @param array<string,mixed>|null $altDb  Se fornito, usa questi parametri
     *                                          invece di quelli in Config.
     *                                          Formato: ['dsn'=>..,'user'=>..,'password'=>..]
     */
    public function __construct(Config $config, ?array $altDb = null)
    {
        try {
            $dsn      = $altDb['dsn']      ?? $config->dbDsn();
            $user     = $altDb['user']     ?? $config->dbUser();
            $password = $altDb['password'] ?? $config->dbPassword();

            $this->pdo = new PDO($dsn, $user, $password, $config->dbOptions());
        } catch (PDOException $e) {
            throw new RuntimeException(
                'Cannot connect to PostgreSQL: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e,
            );
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function exec(string $sql): int
    {
        return (int) $this->pdo->exec($sql);
    }

    public function iterate(string $sql, array $params = []): iterable
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            yield $row;
        }
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function execute(string $sql, array $params = []): int
    {
        // Se c'è una transazione aperta, assicurati che non interferisca
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }
}