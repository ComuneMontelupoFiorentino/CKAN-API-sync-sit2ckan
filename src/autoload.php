<?php

declare(strict_types=1);

/**
 * Autoloader PSR-4 artigianale — sostituisce Composer.
 *
 * Mappa il namespace radice "CkanScheduler\" sulla directory src/.
 * Esempio:
 *   CkanScheduler\Service\CkanUploader  →  src/Service/CkanUploader.php
 *   CkanScheduler\Config\Config         →  src/Config/Config.php
 *
 * Non richiede alcuna dipendenza esterna.
 */

$__srcDir          = __DIR__ . '/';
$__namespacePrefix = 'CkanScheduler\\';

spl_autoload_register(static function (string $fullyQualifiedClass) use ($__srcDir, $__namespacePrefix): void {

    // Lavora solo sulle classi del nostro namespace.
    if (strncmp($fullyQualifiedClass, $__namespacePrefix, strlen($__namespacePrefix)) !== 0) {
        return;
    }

    // Rimuove il prefisso e converte \ in separatore di directory.
    $relative = substr($fullyQualifiedClass, strlen($__namespacePrefix));
    $filePath = $__srcDir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

    if (file_exists($filePath)) {
        require_once $filePath;
    }
});