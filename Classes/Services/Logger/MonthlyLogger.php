<?php

namespace Classes\Services\Logger;

class MonthlyLogger
{
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/');
    }

    public function log(string $action, string $message): void
    {
        $date = new \DateTime();

        $dir = sprintf(
            '%s/%s/%s',
            $this->basePath,
            $date->format('Y'),
            $date->format('m')
        );

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $file = "{$dir}/{$action}.log";

        file_put_contents(
            $file,
            sprintf("[%s] %s\n", $date->format('c'), $message),
            FILE_APPEND
        );
    }
}
