<?php

declare(strict_types=1);

namespace CkanScheduler\Config;

use InvalidArgumentException;

/**
 * Immutable, strictly-typed configuration container.
 * All required keys are validated at construction time so failures are
 * surfaced immediately rather than mid-run.
 *
 * Istanziato esclusivamente tramite IniConfigLoader::load().
 */
final class Config
{
    /**
     * @param array<string,mixed> $raw  Merged config array (common + env).
     * @param string              $env  Ambiente attivo: "production" | "test".
     */
    public function __construct(
        private readonly array  $raw,
        private readonly string $env = 'production',
    ) {
        $this->validate();
    }

    /** Restituisce l'ambiente attivo ("production" | "test"). */
    public function env(): string { return $this->env; }

    /** TRUE se si sta eseguendo in ambiente di test. */
    public function isTest(): bool { return $this->env === 'test'; }

    // ── DB ────────────────────────────────────────────────────────────────────

    public function dbDsn(): string      { return $this->req('db.dsn'); }
    public function dbUser(): string     { return $this->req('db.user'); }
    public function dbPassword(): string { return $this->req('db.password'); }

    /** @return array<int,mixed> */
    public function dbOptions(): array   { return $this->raw['db']['options'] ?? []; }

    // ── CKAN ──────────────────────────────────────────────────────────────────

    public function ckanBaseUrl(): string { return rtrim($this->req('ckan.base_url'), '/'); }
    public function ckanApiKey(): string  { return $this->req('ckan.api_key'); }

    // ── Retry ─────────────────────────────────────────────────────────────────

    public function retryMaxAttempts(): int  { return (int) ($this->raw['retry']['max_attempts']  ?? 3); }
    public function retryBaseDelayMs(): int  { return (int) ($this->raw['retry']['base_delay_ms'] ?? 500); }
    public function retryMultiplier(): float { return (float)($this->raw['retry']['multiplier']   ?? 2.0); }
    public function retryMaxDelayMs(): int   { return (int) ($this->raw['retry']['max_delay_ms']  ?? 10000); }

    // ── Filesystem ────────────────────────────────────────────────────────────

    public function outputDir(): string  { return $this->req('filesystem.output_dir'); }
    public function lockFile(): string   { return $this->req('filesystem.lock_file'); }

    // ── Tables ────────────────────────────────────────────────────────────────

    public function tableResourceLog(): string { return $this->req('tables.resource_log'); }
    public function tableErrorLog(): string    { return $this->req('tables.error_log'); }

    // ── Scheduling ────────────────────────────────────────────────────────────

    public function materializedView(): string { return $this->req('scheduling.materialized_view'); }
    public function schedulingQuery(): string  { return $this->req('scheduling.query'); }

    // ── Internal ─────────────────────────────────────────────────────────────

    /** Read a dot-notation key from the raw config array. */
    private function req(string $path): string
    {
        [$section, $key] = explode('.', $path, 2);
        $value = $this->raw[$section][$key] ?? null;

        if ($value === null || $value === '') {
            throw new InvalidArgumentException("Config key '{$path}' is missing or empty.");
        }

        return (string) $value;
    }

    private function validate(): void
    {
        $required = [
            'db.dsn', 'db.user', 'db.password',
            'ckan.base_url', 'ckan.api_key',
            'filesystem.output_dir', 'filesystem.lock_file',
            'tables.resource_log', 'tables.error_log',
            'scheduling.materialized_view', 'scheduling.query',
        ];

        foreach ($required as $key) {
            $this->req($key); // throws on missing
        }
    }

    /**
     * Restituisce i parametri di connessione per un DB alternativo
     * se esiste la sezione [db_<origin>] nel .ini, altrimenti null.
     *
     * @return array<string,string>|null
     */
    public function alternativeDb(string $origin): ?array
    {
        $section = 'db_' . strtolower($origin);
        $data    = $this->raw[$section] ?? null;

        if (!is_array($data) || empty($data['dbname'])) {
            return null;
        }

        // Eredita host, port, user, password dal [db] principale
        // e sovrascrive solo il dbname
        $host = $this->raw['db']['host'] ?? 'localhost';
        $port = $this->raw['db']['port'] ?? 5432;

        return [
            'dsn'      => "pgsql:host={$host};port={$port};dbname={$data['dbname']}",
            'user'     => $this->dbUser(),
            'password' => $this->dbPassword(),
        ];
    }
}
