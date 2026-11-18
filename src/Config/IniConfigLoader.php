<?php

declare(strict_types=1);

namespace CkanScheduler\Config;

use InvalidArgumentException;
use RuntimeException;

/**
 * Carica la configurazione da file .ini e costruisce un oggetto Config.
 *
 * Strategia di merge (cascading):
 *  1. Parte dai valori di  config/common.ini  (valori condivisi, committabili)
 *  2. Li sovrascrive con   config/env/<env>.ini  (credenziali, path specifici)
 *
 * Il placeholder BASE_DIR nelle stringhe dei file .ini viene sostituito
 * a runtime con il percorso assoluto della root del progetto.
 *
 * Ambienti supportati: "production" | "test"
 * Selezione tramite:
 *   - Flag CLI  --env=production  (priorità massima)
 *   - Variabile d'ambiente  APP_ENV=production
 *   - Default: "production"
 *
 * Uso:
 *   $config = IniConfigLoader::load($baseDir, $env);
 */
final class IniConfigLoader
{
    private const ALLOWED_ENVS = ['production', 'test'];

    /**
     * Risolve l'ambiente e costruisce il Config.
     *
     * @param string      $baseDir  Percorso assoluto alla root del progetto.
     * @param string|null $env      Ambiente esplicito (sovrascrive APP_ENV e default).
     */
    public static function load(string $baseDir, ?string $env = null): Config
    {
        $env = self::resolveEnv($env);

        $commonPath = $baseDir . '/config/common.ini';
        $envPath    = $baseDir . '/config/env/' . $env . '.ini';

        self::assertReadable($commonPath, 'common.ini');
        self::assertReadable($envPath, "env/{$env}.ini");

        $common = self::parseIni($commonPath);
        $envCfg = self::parseIni($envPath);

        // Deep merge: i valori dell'env sovrascrivono quelli comuni sezione per sezione.
        $merged = self::deepMerge($common, $envCfg);

        // Costruisce il DSN PostgreSQL dalla sezione [db] dell'env.
        $merged = self::buildDsn($merged);

        // Sostituisce il placeholder BASE_DIR con il path reale.
        $merged = self::replacePlaceholders($merged, $baseDir);

        // Aggiunge le opzioni PDO (non esprimibili in INI).
        $merged['db']['options'] = [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        return new Config($merged, $env);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private static function resolveEnv(?string $explicit): string
    {
        $env = $explicit
            ?? ($_SERVER['APP_ENV'] ?? null)
            ?? getenv('APP_ENV')
            ?: 'production';

        $env = strtolower(trim($env));

        if (!in_array($env, self::ALLOWED_ENVS, true)) {
            throw new InvalidArgumentException(
                "Invalid environment '{$env}'. Allowed values: "
                . implode(', ', self::ALLOWED_ENVS)
            );
        }

        return $env;
    }

    private static function assertReadable(string $path, string $label): void
    {
        if (!file_exists($path) || !is_readable($path)) {
            throw new RuntimeException(
                "Config file not found or not readable: {$path}\n"
                . "Hint: copy the corresponding .ini.example file and fill in your values."
            );
        }
    }

    /**
     * @return array<string,array<string,string>>
     */
    private static function parseIni(string $path): array
    {
        $result = parse_ini_file($path, process_sections: true, scanner_mode: INI_SCANNER_TYPED);

        if ($result === false) {
            throw new RuntimeException("Cannot parse INI file: {$path}");
        }

        return $result;
    }

    /**
     * Merge ricorsivo di due array associativi.
     * I valori di $override prevalgono su quelli di $base, sezione per sezione.
     *
     * @param array<string,mixed> $base
     * @param array<string,mixed> $override
     * @return array<string,mixed>
     */
    private static function deepMerge(array $base, array $override): array
    {
        foreach ($override as $section => $values) {
            if (is_array($values) && isset($base[$section]) && is_array($base[$section])) {
                $base[$section] = array_merge($base[$section], $values);
            } else {
                $base[$section] = $values;
            }
        }

        return $base;
    }

    /**
     * Costruisce il DSN PDO PostgreSQL dalla sezione [db].
     * Rimuove host/port/dbname da $merged['db'] dopo averli usati.
     *
     * @param array<string,mixed> $merged
     * @return array<string,mixed>
     */
    private static function buildDsn(array $merged): array
    {
        $db = $merged['db'] ?? [];

        $host   = $db['host']   ?? 'localhost';
        $port   = $db['port']   ?? 5432;
        $dbname = $db['dbname'] ?? '';

        if ($dbname === '') {
            throw new InvalidArgumentException("INI [db] section is missing 'dbname'.");
        }

        $merged['db']['dsn'] = "pgsql:host={$host};port={$port};dbname={$dbname}";

        // Rimuove le singole chiavi per non confondere Config::req()
        unset($merged['db']['host'], $merged['db']['port'], $merged['db']['dbname']);

        return $merged;
    }

    /**
     * Sostituisce la stringa letterale "BASE_DIR" con il path assoluto reale
     * in tutti i valori stringa dell'array di configurazione.
     *
     * @param array<string,mixed> $cfg
     * @return array<string,mixed>
     */
    private static function replacePlaceholders(array $cfg, string $baseDir): array
    {
        array_walk_recursive($cfg, static function (mixed &$value) use ($baseDir): void {
            if (is_string($value)) {
                $value = str_replace('BASE_DIR', $baseDir, $value);
            }
        });

        return $cfg;
    }
}
