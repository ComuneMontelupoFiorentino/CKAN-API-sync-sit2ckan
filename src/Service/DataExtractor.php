<?php

declare(strict_types=1);

namespace CkanScheduler\Service;

use CkanScheduler\Database\ConnectionPool;
use CkanScheduler\Enum\FileExtension;
use CkanScheduler\Exception\ExtractionException;
use CkanScheduler\Exception\FilesystemException;
use CkanScheduler\Model\SchedulationRecord;
use CkanScheduler\Writer\XlsxWriter;

final class DataExtractor
{
    public function __construct(
        private readonly ConnectionPool $pool,
        private readonly string         $outputDir,
    ) {}

    public function extract(SchedulationRecord $record): string
    {
        $dir      = $this->outputDir . '/' . $record->outputSubdir();
        $filePath = $dir . '/' . $record->filename();

        $this->ensureDir($dir);

        // Usa la connessione giusta per il database_origin del record
        $db = $this->pool->forOrigin($record->databaseOrigin);

        try {
            $rows = $db->fetchAll($record->sourceQuery);
        } catch (\Throwable $e) {
            throw new ExtractionException(
                "Query fallita per resource_id '{$record->resourceId}' "
                . "(origin: {$record->databaseOrigin}): " . $e->getMessage(),
                0,
                $e,
            );
        }

        $content = match ($record->fileExtension) {
            FileExtension::CSV     => $this->renderCsv($rows),
            FileExtension::JSON    => $this->renderJson($rows),
            FileExtension::GeoJSON => $this->renderGeoJson($rows),
            FileExtension::RDF     => $this->renderRdf($rows, $record->resourceId),
            FileExtension::XLSX    => (new XlsxWriter())->render($rows, $record->resourceName),
        };

        $this->writeFile($filePath, $content);

        return $filePath;
    }

    private function renderCsv(array $rows): string
    {
        if (empty($rows)) {
            return '';
        }
        $buf = fopen('php://temp', 'r+');
        if ($buf === false) {
            throw new FilesystemException("Impossibile aprire il buffer temporaneo per il CSV.");
        }
        fputcsv($buf, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($buf, array_values($row));
        }
        rewind($buf);
        $out = (string) stream_get_contents($buf);
        fclose($buf);
        return $out;
    }

    /**
     * JSON — gestisce due casi:
     *
     *  1. Query "aggregata": restituisce UNA sola riga con UNA sola colonna
     *     il cui valore è già una stringa JSON valida (tipico pattern
     *     "SELECT jsonb_build_object(...) AS json_output FROM ...").
     *     In questo caso il valore viene scritto DIRETTAMENTE nel file,
     *     senza un ulteriore json_encode che lo ravvolgerebbe in un array
     *     con escape raddoppiato (es. [{"json_output": "{\"a\":1}"}]).
     *
     *  2. Query "tabellare" normale: ogni riga del result set diventa
     *     un elemento di un array JSON (comportamento originale).
     *
     * @param array<int,array<string,mixed>> $rows
     */
    private function renderJson(array $rows): string
    {
        // Caso 1: una sola riga, una sola colonna, valore stringa che è già JSON valido.
        if (count($rows) === 1 && count($rows[0]) === 1) {
            $value = reset($rows[0]);

            if (is_string($value)) {
                $decoded = json_decode($value, true);

                if (json_last_error() === JSON_ERROR_NONE && ($decoded !== null || $value === 'null')) {
                    return json_encode(
                        $decoded,
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                    );
                }
            }
        }

        // Caso 2: comportamento standard, un array di righe.
        return json_encode(
            $rows,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    private function renderGeoJson(array $rows): string
    {
        $features = [];

        foreach ($rows as $row) {
            // Caso 1: colonna geometry/geom con GeoJSON stringa
            $geomRaw = $row['geometry'] ?? $row['geom'] ?? null;

            // Caso 2: coordinate lat/lon come colonne scalari
            $lat = isset($row['lat']) ? (float) $row['lat'] : null;
            $lon = isset($row['lon']) ? (float) $row['lon'] : null;

            // Proprietà: tutto tranne le colonne geometriche
            $properties = $row;
            unset($properties['geometry'], $properties['geom']);

            // Costruisce l'oggetto geometry
            if ($geomRaw !== null && is_string($geomRaw)) {
                // GeoJSON stringa dal DB
                $decoded  = json_decode($geomRaw, true);
                $geometry = is_array($decoded)
                    ? $decoded
                    : ['type' => 'Unknown', 'coordinates' => $geomRaw];

            } elseif ($lat !== null && $lon !== null) {
                // Coordinate scalari lat/lon → Point GeoJSON
                // GeoJSON vuole [longitude, latitude]
                $geometry = [
                    'type'        => 'Point',
                    'coordinates' => [$lon, $lat],
                ];

            } else {
                $geometry = null;
            }

            $features[] = [
                'type'       => 'Feature',
                'geometry'   => $geometry,
                'properties' => $properties,
            ];
        }

        return json_encode(
            ['type' => 'FeatureCollection', 'features' => $features],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    private function renderRdf(array $rows, string $resourceId): string
    {
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"',
            '         xmlns:dc="http://purl.org/dc/elements/1.1/">',
        ];
        foreach ($rows as $i => $row) {
            $subject = 'urn:ckan:' . $resourceId . ':row:' . ($row['id'] ?? $i);
            $lines[] = "  <rdf:Description rdf:about=\"{$subject}\">";
            foreach ($row as $col => $val) {
                $col     = htmlspecialchars((string) $col, ENT_XML1, 'UTF-8');
                $val     = htmlspecialchars((string) $val, ENT_XML1, 'UTF-8');
                $lines[] = "    <dc:{$col}>{$val}</dc:{$col}>";
            }
            $lines[] = '  </rdf:Description>';
        }
        $lines[] = '</rdf:RDF>';
        return implode("\n", $lines);
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new FilesystemException("Impossibile creare la directory: {$dir}");
        }
    }

    private function writeFile(string $path, string $content): void
    {
        if (file_put_contents($path, $content, LOCK_EX) === false) {
            throw new FilesystemException("Impossibile scrivere il file: {$path}");
        }
    }
}