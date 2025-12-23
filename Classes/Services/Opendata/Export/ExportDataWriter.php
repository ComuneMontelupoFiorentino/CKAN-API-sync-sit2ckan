<?php

namespace Classes\Services\Opendata\Export;

use RuntimeException;

class ExportDataWriter
{
    private string $resourceName;
    private string $fileExtension;
    private array $fields;
    private array $records;
    private string $outputDir;
    private string $logDir;
    private string $logFileName;

    public function __construct(
        string $resourceName,
        string $fileExtension,
        array $fields,
        array $records,
        string $outputDir,
        string $logBaseDir
    ) {
        $this->resourceName  = $resourceName;
        $this->fileExtension = strtolower($fileExtension);
        $this->fields        = $fields;
        $this->records       = $records;
        $this->outputDir     = rtrim($outputDir, '/');
    
        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0775, true);
        }
    
        // ======================
        // LOG PATH YYYY/MM
        // ======================
        $year  = date('Y');
        $month = date('m');
    
        $this->logDir = rtrim($logBaseDir, '/') . "/{$year}/{$month}";
        $this->logFileName = 'export.log';
    
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0775, true);
        }
    }    

    /**
     * Entry point unico
     */
    public function export(
        array $options = []
    ): string {
        $filePath = "{$this->outputDir}/{$this->resourceName}.{$this->fileExtension}";

        switch ($this->fileExtension) {
            case 'csv':
                $this->exportCSV($filePath);
                break;

            case 'json':
                $this->exportJSON($filePath);
                break;

            case 'rdf':
                $this->exportRDF(
                    $filePath,
                    $options['rdf_base_url'] ?? '',
                    $options['rdf_namespaces'] ?? []
                );
                break;

            case 'geojson':
                $this->exportGeoJSON($filePath);
                break;

            default:
                throw new RuntimeException(
                    "Formato di esportazione non supportato: {$this->fileExtension}"
                );
        }

        $this->log("Export {$this->fileExtension} generato: {$filePath}");
        return $filePath;
    }

    /* ================= CSV ================= */

    private function exportCSV(string $filePath): void
    {
        if (empty($this->records)) {
            throw new RuntimeException("Nessun record da esportare in CSV");
        }

        $fp = fopen($filePath, 'w');
        if (!$fp) {
            throw new RuntimeException("Impossibile scrivere CSV: {$filePath}");
        }

        fputcsv($fp, $this->fields);

        foreach ($this->records as $row) {
            fputcsv($fp, $row);
        }

        fclose($fp);
    }

    /* ================= JSON ================= */

    private function exportJSON(string $filePath): void
    {
        file_put_contents(
            $filePath,
            json_encode(
                $this->records,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            )
        );
    }

    /* ================= RDF ================= */

    private function exportRDF(
        string $filePath,
        string $baseUrl,
        array $namespaces
    ): void {
        if (empty($baseUrl)) {
            throw new RuntimeException("Base URL RDF mancante");
        }

        if (!in_array('identificativo', $this->fields, true)) {
            throw new RuntimeException(
                "Export RDF: campo 'identificativo' obbligatorio"
            );
        }

        $rdf  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $rdf .= "<rdf:RDF";

        foreach ($namespaces as $prefix => $uri) {
            $rdf .= " xmlns:$prefix=\"$uri\"";
        }

        $rdf .= ">\n";

        foreach ($this->records as $row) {
            $id = $row['identificativo'];

            $rdf .= "  <rdf:Description rdf:about=\"{$baseUrl}{$id}\">\n";

            foreach ($row as $field => $value) {
                if ($field === 'geom') {
                    continue;
                }

                $safe = htmlspecialchars((string) $value, ENT_XML1);
                $rdf .= "    <ex:$field>{$safe}</ex:$field>\n";
            }

            $rdf .= "  </rdf:Description>\n";
        }

        $rdf .= "</rdf:RDF>";

        file_put_contents($filePath, $rdf);
    }

    /* ================= GEOJSON ================= */

    private function exportGeoJSON(string $filePath): void
    {
        if (!in_array('geom', $this->fields, true)) {
            throw new RuntimeException(
                "Export GeoJSON: campo 'geom' mancante"
            );
        }

        $features = [];

        foreach ($this->records as $row) {
            $geometry = json_decode($row['geom'], true);

            if (!$geometry) {
                throw new RuntimeException(
                    "Geometria GeoJSON non valida"
                );
            }

            $props = $row;
            unset($props['geom']);

            $features[] = [
                'type'       => 'Feature',
                'geometry'   => $geometry,
                'properties' => $props
            ];
        }

        $geojson = [
            'type'     => 'FeatureCollection',
            'features' => $features
        ];

        file_put_contents(
            $filePath,
            json_encode(
                $geojson,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            )
        );
    }

    /* ================= LOG ================= */

    private function log(string $msg): void
    {
        $filePath = "{$this->logDir}/{$this->logFileName}";

        file_put_contents(
            $filePath,
            '[' . date('Y-m-d H:i:s') . "] {$msg}\n",
            FILE_APPEND
        );
    }

}
