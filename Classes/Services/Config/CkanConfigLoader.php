<?php

namespace Classes\Services\Config;

use RuntimeException;

class CkanConfigLoader
{
    public static function load(string $env): array
    {
        $file = __DIR__ . '/../../../config/ckan_client_config.ini';

        if (!file_exists($file)) {
            throw new RuntimeException('ckan_client_config.ini non trovato');
        }

        $data = parse_ini_file($file, true);

        $section = "ckan_{$env}";
        if (!isset($data[$section])) {
            throw new RuntimeException("Ambiente {$section} non definito");
        }

        return $data[$section];
    }
}
