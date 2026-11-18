#!/usr/bin/env php
<?php

declare(strict_types=1);

define('BASE_DIR', dirname(__DIR__));
define('START_TIME', microtime(true));

require_once BASE_DIR . '/src/autoload.php';

use CkanScheduler\Application;
use CkanScheduler\Config\IniConfigLoader;
use CkanScheduler\Exception\LockException;

// ── Parsing argomenti CLI ─────────────────────────────────────────────────────
// Uso:  php bin/scheduler.php [--env=production|test]
$opts = getopt('', ['env:']);
$env  = $opts['env'] ?? null;

// ── Bootstrap configurazione da .ini ─────────────────────────────────────────
try {
    $config = IniConfigLoader::load(BASE_DIR, $env);
} catch (Throwable $e) {
    fwrite(STDERR, "[FATAL] Errore configurazione: " . $e->getMessage() . "\n");
    exit(2);
}

// ── Esecuzione ────────────────────────────────────────────────────────────────
try {
    $app      = new Application($config, dryRun: false);
    $exitCode = $app->run();
    exit($exitCode);
} catch (LockException $e) {
    fwrite(STDERR, "[LOCK] " . $e->getMessage() . "\n");
    exit(3);
} catch (Throwable $e) {
    fwrite(STDERR, "[FATAL] " . $e->getMessage() . " in " . $e->getFile() . ':' . $e->getLine() . "\n");
    exit(1);
}
