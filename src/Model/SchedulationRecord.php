<?php

declare(strict_types=1);

namespace CkanScheduler\Model;

use CkanScheduler\Enum\FileExtension;
use InvalidArgumentException;

final class SchedulationRecord
{
    public function __construct(
        public int           $id,
        public string        $resourceId,
        public string        $resourceName,
        public FileExtension $fileExtension,
        public string        $databaseOrigin,
        public int           $schedulation,
        public bool          $isFirst,
        public string        $sourceQuery,
    ) {}

    public static function fromRow(array $row): self
    {
        $id             = (int) self::requireString($row, 'id');
        $resourceId     = self::requireString($row, 'resource_id');
        $resourceName   = self::requireString($row, 'resource_name');
        $databaseOrigin = self::requireString($row, 'database_origin');
        $schedulation   = (int) ($row['schedulation'] ?? 0);

        $extRaw = self::requireString($row, 'file_extension');
        $ext    = FileExtension::tryFrom(strtolower($extRaw));

        if ($ext === null) {
            throw new InvalidArgumentException(
                "file_extension non supportato '{$extRaw}' per resource_id '{$resourceId}'. " .
                "Valori ammessi: " . implode(', ', array_column(FileExtension::cases(), 'value'))
            );
        }

        $raw     = $row['json_params'] ?? null;
        $decoded = is_string($raw)
            ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR)
            : $raw;

        if (!is_array($decoded) || !isset($decoded['query'])) {
            throw new InvalidArgumentException(
                "json_params manca della chiave 'query' per resource_id '{$resourceId}'."
            );
        }

        $sourceQuery = trim((string) $decoded['query']);
        if ($sourceQuery === '') {
            throw new InvalidArgumentException(
                "json_params contiene una query vuota per resource_id '{$resourceId}'."
            );
        }

        $isFirst = filter_var($row['is_first'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return new self(
            id:             $id,
            resourceId:     $resourceId,
            resourceName:   $resourceName,
            fileExtension:  $ext,
            databaseOrigin: $databaseOrigin,
            schedulation:   $schedulation,
            isFirst:        $isFirst,
            sourceQuery:    $sourceQuery,
        );
    }

    public function filename(): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $this->resourceName);
        return $safe . '.' . $this->fileExtension->value;
    }

    public function outputSubdir(): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '_', $this->databaseOrigin);
    }

    private static function requireString(array $row, string $key): string
    {
        if (!array_key_exists($key, $row) || $row[$key] === null || $row[$key] === '') {
            throw new InvalidArgumentException(
                "Campo obbligatorio '{$key}' assente o vuoto nella riga."
            );
        }
        return (string) $row[$key];
    }
}