<?php

declare(strict_types=1);

namespace CkanScheduler\Service;

use CkanScheduler\Exception\CkanApiException;
use CkanScheduler\Model\SchedulationRecord;

/**
 * Contratto per l'upload di una singola risorsa su CKAN.
 *
 * Due implementazioni:
 *  - CkanUploader   → upload reale via API
 *  - DryRunUploader → simula l'upload senza toccare il portale
 */
interface UploaderInterface
{
    /**
     * @return array<string,mixed>  Risposta decodificata dall'API CKAN.
     * @throws CkanApiException
     */
    public function uploadResource(SchedulationRecord $record, string $filePath): array;
}
