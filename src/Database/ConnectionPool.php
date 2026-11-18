<?php

declare(strict_types=1);

namespace CkanScheduler\Database;

use CkanScheduler\Config\Config;
use PDO;

/**
 * Pool di connessioni PDO.
 *
 * Gestisce una connessione principale (sezione [db] del .ini)
 * e connessioni opzionali per database alternativi
 * (sezioni [db_<database_origin>] del .ini).
 *
 * Se per un certo database_origin non esiste una sezione dedicata,
 * viene restituita la connessione principale.
 */
final class ConnectionPool
{
    /** @var array<string,Connection> */
    private array $pool = [];

    private Connection $main;

    public function __construct(private readonly Config $config)
    {
        $this->main = new Connection($config);
        $this->pool['__main__'] = $this->main;
    }

    /**
     * Restituisce la connessione per il database_origin indicato.
     * Se non esiste una sezione [db_<origin>] nel .ini, usa quella principale.
     */
    public function forOrigin(string $databaseOrigin): Connection
    {
        $key = strtolower($databaseOrigin);

        if (isset($this->pool[$key])) {
            return $this->pool[$key];
        }

        $altConfig = $this->config->alternativeDb($key);

        if ($altConfig === null) {
            // Nessuna sezione dedicata → usa la connessione principale
            $this->pool[$key] = $this->main;
            return $this->main;
        }

        // Crea una nuova connessione per questo DB
        $this->pool[$key] = new Connection($this->config, $altConfig);
        return $this->pool[$key];
    }

    /**
     * Connessione principale (usata per la vista materializzata e i log).
     */
    public function main(): Connection
    {
        return $this->main;
    }
}