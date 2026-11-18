<?php

declare(strict_types=1);

namespace CkanScheduler\Http;

use CkanScheduler\Config\Config;
use CkanScheduler\Exception\CkanApiException;

/**
 * Minimal cURL-based HTTP client with configurable exponential-backoff retry.
 * All CKAN API interactions go through this class so retry/logging is centralised.
 */
final class RetryHttpClient
{
    public function __construct(private readonly Config $config) {}

    /**
     * POST multipart/form-data – used for CKAN resource_update (file upload).
     *
     * @param array<string,mixed> $fields   Form fields (non-file)
     * @param array<string,array{path:string,mime:string,name:string}> $files
     * @return array<string,mixed>          Decoded JSON response
     * @throws CkanApiException
     */
    public function postMultipart(string $url, array $fields, array $files = []): array
    {
        $postData = $fields;
        foreach ($files as $fieldName => $file) {
            $postData[$fieldName] = new \CURLFile(
                $file['path'],
                $file['mime'],
                $file['name'],
            );
        }

        return $this->request('POST', $url, $postData, true);
    }

    /**
     * POST application/json.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     * @throws CkanApiException
     */
    public function postJson(string $url, array $payload): array
    {
        return $this->request('POST', $url, $payload, false);
    }

    // ── Core retry loop ───────────────────────────────────────────────────────

    /**
     * @param array<string,mixed>|\CURLFile[] $data
     * @return array<string,mixed>
     */
    private function request(string $method, string $url, array $data, bool $multipart): array
    {
        $maxAttempts  = $this->config->retryMaxAttempts();
        $delayMs      = $this->config->retryBaseDelayMs();
        $multiplier   = $this->config->retryMultiplier();
        $maxDelayMs   = $this->config->retryMaxDelayMs();
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return $this->doRequest($method, $url, $data, $multipart);
            } catch (CkanApiException $e) {
                $lastException = $e;

                // Do not retry on client errors (4xx) – they won't fix themselves.
                if ($e->httpStatus >= 400 && $e->httpStatus < 500) {
                    throw $e;
                }

                // 500 = "bug noto del datastore" di dati.toscana.it: il file e' gia' stato
                // caricato e Application lo considera riuscito. Ritentare voleva dire
                // caricare ogni risorsa 3 volte (log del 19/10: 28 risorse x 3).
                // 502/503/504 e gli errori di rete restano ritentati.
                if ($e->httpStatus === 500) {
                    throw $e;
                }

                if ($attempt < $maxAttempts) {
                    $sleepUs = $delayMs * 1000;
                    fwrite(STDERR,
                        "[HTTP] Attempt {$attempt}/{$maxAttempts} failed ({$e->getMessage()}). " .
                        "Retrying in " . round($delayMs / 1000, 2) . "s…\n"
                    );
                    usleep($sleepUs);
                    $delayMs = (int) min($delayMs * $multiplier, $maxDelayMs);
                }
            }
        }

        throw $lastException ?? new CkanApiException("Unknown HTTP error after {$maxAttempts} attempts.");
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     * @throws CkanApiException
     */
    private function doRequest(string $method, string $url, array $data, bool $multipart): array
    {
        $ch = curl_init();

        if ($multipart) {
            // Multipart: solo Authorization, lascia che cURL gestisca
            // automaticamente Content-Type con il boundary corretto.
            // Replica esatta del vecchio UploadFileTask funzionante.
            $headers  = ["Authorization: " . $this->config->ckanApiKey()];
            $postBody = $data;
        } else {
            $headers  = [
                "Authorization: " . $this->config->ckanApiKey(),
                "Content-Type: application/json",
            ];
            $postBody = json_encode($data, JSON_THROW_ON_ERROR);
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POST           => ($method === 'POST'),
            CURLOPT_POSTFIELDS     => $postBody,
        ]);

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $error !== '') {
            throw new CkanApiException("cURL error: {$error}", 0, '');
        }

        $body    = (string) $body;
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new CkanApiException("Non-JSON response from CKAN", $status, $body);
        }

        if ($status >= 400 || ($decoded['success'] ?? true) === false) {
            $errMsg = $decoded['error']['message']
                ?? ($decoded['error']['__type'] ?? 'Unknown CKAN error');
            throw new CkanApiException(
                "CKAN API error [{$status}]: {$errMsg}",
                $status,
                $body,
            );
        }

        return $decoded;
    }
}
