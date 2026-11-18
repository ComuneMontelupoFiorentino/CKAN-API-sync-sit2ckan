#!/usr/bin/env php
<?php

declare(strict_types=1);

define('BASE_DIR', dirname(__DIR__));
define('START_TIME', microtime(true));

require_once BASE_DIR . '/src/autoload.php';

use CkanScheduler\Application;
use CkanScheduler\Config\IniConfigLoader;
use CkanScheduler\Exception\LockException;

// ── Banner ────────────────────────────────────────────────────────────────────
$bold   = "\033[1m";
$yellow = "\033[33m";
$reset  = "\033[0m";

echo PHP_EOL;
echo "{$bold}{$yellow}╔══════════════════════════════════════════════════════════════╗{$reset}\n";
echo "{$bold}{$yellow}║         CKAN SCHEDULER — MODALITÀ DRY-RUN                    ║{$reset}\n";
echo "{$bold}{$yellow}║  Nessun dato sarà caricato su CKAN. Nessun DB sarà aggiornato ║{$reset}\n";
echo "{$bold}{$yellow}╚══════════════════════════════════════════════════════════════╝{$reset}\n";
echo PHP_EOL;

// ── Parsing argomenti CLI ─────────────────────────────────────────────────────
$opts = getopt('', ['env:']);
$env  = $opts['env'] ?? 'test';

echo "Ambiente  : {$bold}{$env}{$reset}\n";
echo "Modalità  : {$bold}DRY-RUN (nessuna scrittura su CKAN){$reset}\n";
echo "Avviato il: " . date('Y-m-d H:i:s') . PHP_EOL;
echo str_repeat('─', 65) . PHP_EOL . PHP_EOL;

// ── Bootstrap configurazione da .ini ─────────────────────────────────────────
try {
    $config = IniConfigLoader::load(BASE_DIR, $env);
} catch (Throwable $e) {
    fwrite(STDERR, "[FATAL] Errore configurazione: " . $e->getMessage() . "\n");
    exit(2);
}

// ── Esecuzione in modalità dry-run ────────────────────────────────────────────
try {
    $app      = new Application($config, dryRun: true);
    $exitCode = $app->run();
    exit($exitCode);
} catch (LockException $e) {
    fwrite(STDERR, "[LOCK] " . $e->getMessage() . "\n");
    exit(3);
} catch (Throwable $e) {
    fwrite(STDERR, "[FATAL] " . $e->getMessage() . " in " . $e->getFile() . ':' . $e->getLine() . "\n");
    exit(1);
}
