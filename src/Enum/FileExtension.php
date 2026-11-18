<?php

declare(strict_types=1);

namespace CkanScheduler\Enum;

/**
 * Validated, exhaustive list of supported file-export formats.
 * Any value arriving from the DB that is not in this list is rejected
 * before any file-system or network operation is attempted.
 */
enum FileExtension: string
{
    case CSV     = 'csv';
    case JSON    = 'json';
    case RDF     = 'rdf';
    case GeoJSON = 'geojson';
    case XLSX    = 'xlsx';

    /** Mime types used when uploading to CKAN. */
    public function mimeType(): string
    {
        return match ($this) {
            self::CSV     => 'text/csv',
            self::JSON    => 'application/json',
            self::RDF     => 'application/rdf+xml',
            self::GeoJSON => 'application/geo+json',
            self::XLSX    => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };
    }
}
