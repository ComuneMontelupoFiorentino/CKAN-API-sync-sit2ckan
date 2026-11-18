<?php

declare(strict_types=1);

namespace CkanScheduler\Service;

use CkanScheduler\Config\Config;
use CkanScheduler\Exception\CkanApiException;
use CkanScheduler\Http\RetryHttpClient;
use CkanScheduler\Model\SchedulationRecord;

/**
 * Carica una risorsa sul portale CKAN tramite la Action API.
 *
 * Endpoint utilizzato:
 *   POST /api/3/action/resource_update
 *
 * Se la risorsa non esiste su CKAN il server risponde con un errore 404,
 * che viene rilanciatocome CkanApiException (nessuna creazione silenziosa
 * di nuove risorse, per evitare record orfani sul portale).
 */
final class CkanUploader implements UploaderInterface
{
    public function __construct(
        private readonly RetryHttpClient $http,
        private readonly Config          $config,
    ) {}

    /**
     * @return array<string,mixed>  Risposta decodificata dall'API CKAN.
     * @throws CkanApiException
     */
    public function uploadResource(SchedulationRecord $record, string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new CkanApiException("File locale non trovato: {$filePath}");
        }

        $url = $this->config->ckanBaseUrl() . '/api/3/action/resource_update';

        return $this->http->postMultipart(
            $url,
            fields: [
                'id'     => $record->resourceId,
                'format' => strtoupper($record->fileExtension->value),
            ],
            files: [
                'upload' => [
                    'path' => $filePath,
                    'mime' => $record->fileExtension->mimeType(),
                    'name' => basename($filePath),
                ],
            ],
        );
    }
}
