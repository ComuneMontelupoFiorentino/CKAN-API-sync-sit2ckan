<?php

declare(strict_types=1);

namespace CkanScheduler\Logging;

use CkanScheduler\Config\Config;
use CkanScheduler\Database\Connection;
use Throwable;

final class ErrorLogger
{
    public function __construct(
        private readonly Connection $db,
        private readonly Config     $config,
    ) {}

    public function log(
        string     $errorType,
        string     $message,
        array      $context   = [],
        ?Throwable $exception = null,
    ): void {
        $table = $this->config->tableErrorLog();

        $stackTrace = null;
        if ($exception !== null) {
            $stackTrace = get_class($exception) . ': ' . $exception->getMessage()
                . "\n" . $exception->getTraceAsString();
        }

        try {
            $this->db->execute(
                "INSERT INTO {$table}
                    (error_type, resource_id, resource_name,
                     message, server_response, http_status, stack_trace)
                 VALUES
                    (:error_type, :resource_id, :resource_name,
                     :message, :server_response, :http_status, :stack_trace)",
                [
                    ':error_type'      => $errorType,
                    ':resource_id'     => $context['resource_id']     ?? null,
                    ':resource_name'   => $context['resource_name']   ?? null,
                    ':message'         => $message,
                    ':server_response' => $context['server_response'] ?? null,
                    ':http_status'     => $context['http_status']     ?? null,
                    ':stack_trace'     => $stackTrace,
                ],
            );
        } catch (Throwable $e) {
            fwrite(STDERR, "[ERROR-LOGGER-FAIL] {$e->getMessage()}\nOriginal: {$message}\n");
        }
    }

    public function logExtraction(string $message, array $context = [], ?Throwable $e = null): void
    {
        $this->log('extraction', $message, $context, $e);
    }

    public function logUpload(string $message, array $context = [], ?Throwable $e = null): void
    {
        $this->log('upload', $message, $context, $e);
    }

    public function logFilesystem(string $message, array $context = [], ?Throwable $e = null): void
    {
        $this->log('filesystem', $message, $context, $e);
    }

    public function logValidation(string $message, array $context = [], ?Throwable $e = null): void
    {
        $this->log('validation', $message, $context, $e);
    }
}