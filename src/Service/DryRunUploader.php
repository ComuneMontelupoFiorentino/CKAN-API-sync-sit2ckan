<?php

declare(strict_types=1);

namespace CkanScheduler\Service;

use CkanScheduler\Model\SchedulationRecord;

/**
 * Implementazione "dry-run" dell'uploader.
 *
 * Non effettua alcuna chiamata HTTP verso CKAN.
 * Per ogni risorsa stampa su STDOUT un box colorato con:
 *  - resource_id CKAN di destinazione
 *  - schema di origine (database_origin)
 *  - path del file locale generato
 *  - dimensione e checksum MD5
 *  - mime type e formato
 *  - endpoint che verrebbe chiamato
 */
final class DryRunUploader implements UploaderInterface
{
    private int $count = 0;

    /** @return array<string,mixed> */
    public function uploadResource(SchedulationRecord $record, string $filePath): array
    {
        $this->count++;
        $info = $this->fileInfo($filePath);

        $this->printBox(
            tag:   "DRY-RUN #{$this->count}",
            color: "\033[36m",
            lines: [
                "resource_id      : {$record->resourceId}",
                "resource_name    : {$record->resourceName}",
                "database_origin  : {$record->databaseOrigin}",
                "format           : " . strtoupper($record->fileExtension->value),
                "mime_type        : {$record->fileExtension->mimeType()}",
                "local_path       : {$filePath}",
                "file_size        : {$info['size']}",
                "md5              : {$info['md5']}",
                "ckan_endpoint    : POST /api/3/action/resource_update",
            ],
        );

        return [
            'success' => true,
            'dry_run' => true,
            'resource' => ['id' => $record->resourceId],
        ];
    }

    /** Stampa il riepilogo finale. */
    public function printSummary(): void
    {
        $this->printBox(
            tag:   'DRY-RUN — RIEPILOGO FINALE',
            color: "\033[33m",
            lines: [
                "Risorse simulate       : {$this->count}",
                "Chiamate HTTP a CKAN   : 0",
                "Modifiche su CKAN      : NESSUNA",
                "Aggiornamenti su DB    : NESSUNO",
            ],
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array{size:string, md5:string} */
    private function fileInfo(string $path): array
    {
        if (!file_exists($path)) {
            return ['size' => 'FILE NON TROVATO', 'md5' => 'n/a'];
        }

        $bytes = filesize($path);
        return [
            'size' => $bytes === false ? 'n/a' : $this->formatBytes($bytes),
            'md5'  => md5_file($path) ?: 'n/a',
        ];
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024)       return "{$bytes} B";
        if ($bytes < 1_048_576)  return round($bytes / 1024, 1) . ' KB';
        return round($bytes / 1_048_576, 2) . ' MB';
    }

    private function printBox(string $tag, string $color, array $lines): void
    {
        $reset  = "\033[0m";
        $bold   = "\033[1m";
        $width  = 65;
        $border = str_repeat('─', $width);

        echo "{$color}{$bold}┌{$border}┐{$reset}\n";
        echo "{$color}{$bold}│ {$tag}" . str_pad('', $width - strlen($tag) - 1) . "│{$reset}\n";
        echo "{$color}{$bold}├{$border}┤{$reset}\n";
        foreach ($lines as $line) {
            echo "{$color}│ " . str_pad($line, $width - 2) . " │{$reset}\n";
        }
        echo "{$color}{$bold}└{$border}┘{$reset}\n\n";
    }
}
