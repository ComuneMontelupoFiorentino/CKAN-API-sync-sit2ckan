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
// Uso: php bin/upload-single.php --env=production --resource-id=<uuid>
//      php bin/upload-single.php --env=production --resource-name="civici"
$opts        = getopt('', ['env:', 'resource-id:', 'resource-name:']);
$env         = $opts['env'] ?? null;
$resourceId  = $opts['resource-id'] ?? null;
$resourceName = $opts['resource-name'] ?? null;

if ($resourceId === null && $resourceName === null) {
    fwrite(STDERR, "Uso: php bin/upload-single.php --env=production --resource-id=<uuid>\n");
    fwrite(STDERR, "     php bin/upload-single.php --env=production --resource-name=<nome>\n");
    exit(2);
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────
try {
    $config = IniConfigLoader::load(BASE_DIR, $env);
} catch (Throwable $e) {
    fwrite(STDERR, "[FATAL] Errore configurazione: " . $e->getMessage() . "\n");
    exit(2);
}

// ── Esecuzione ────────────────────────────────────────────────────────────────
try {
    $app      = new Application($config, dryRun: false);
    $exitCode = $app->runSingle(
        resourceId:   $resourceId,
        resourceName: $resourceName,
    );
    exit($exitCode);
} catch (LockException $e) {
    fwrite(STDERR, "[LOCK] " . $e->getMessage() . "\n");
    exit(3);
} catch (Throwable $e) {
    fwrite(STDERR, "[FATAL] " . $e->getMessage() . " in " . $e->getFile() . ':' . $e->getLine() . "\n");
    exit(1);
}