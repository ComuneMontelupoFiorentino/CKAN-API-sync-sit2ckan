<?php
declare(strict_types=1);
namespace CkanScheduler\Exception;
use RuntimeException;

class CkanApiException extends RuntimeException
{
    public int    $httpStatus   = 0;
    public string $responseBody = '';

    public function __construct(
        string     $message,
        int        $httpStatus   = 0,
        string     $responseBody = '',
        \Throwable $previous     = null,
    ) {
        $this->httpStatus   = $httpStatus;
        $this->responseBody = $responseBody;
        parent::__construct($message, $httpStatus, $previous);
    }
}