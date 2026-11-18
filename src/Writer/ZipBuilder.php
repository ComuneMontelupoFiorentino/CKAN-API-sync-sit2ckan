<?php

declare(strict_types=1);

namespace CkanScheduler\Writer;

/**
 * Costruttore minimale di archivi ZIP in PHP puro (nessuna dipendenza da ext-zip).
 *
 * Usa DEFLATE se ext-zlib è disponibile (gzdeflate), altrimenti STORE.
 * Pensato per pacchetti di piccole/medie dimensioni come i file XLSX:
 * l'intero archivio viene costruito in memoria. Non supporta ZIP64
 * (limite 4 GB per file / 65535 voci), ampiamente sufficiente per un XLSX.
 */
final class ZipBuilder
{
    /**
     * @param array<string,string> $files  path interno → contenuto
     * @return string Contenuto binario dell'archivio
     */
    public function build(array $files): string
    {
        $useDeflate = function_exists('gzdeflate');
        [$dosTime, $dosDate] = $this->dosDateTime(time());

        $data    = '';
        $central = '';
        $count   = 0;

        foreach ($files as $name => $content) {
            $crc      = crc32($content);
            $size     = strlen($content);
            $method   = 0;
            $payload  = $content;

            if ($useDeflate) {
                $deflated = gzdeflate($content, 6);
                if ($deflated !== false && strlen($deflated) < $size) {
                    $payload = $deflated;
                    $method  = 8;
                }
            }

            $compSize = strlen($payload);
            $offset   = strlen($data);
            $flags    = 0x0800; // nomi file in UTF-8

            // Local file header
            $data .= pack(
                'VvvvvvVVVvv',
                0x04034b50, 20, $flags, $method, $dosTime, $dosDate,
                $crc, $compSize, $size, strlen($name), 0,
            ) . $name . $payload;

            // Central directory entry
            $central .= pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50, 20, 20, $flags, $method, $dosTime, $dosDate,
                $crc, $compSize, $size, strlen($name), 0, 0, 0, 0, 0, $offset,
            ) . $name;

            $count++;
        }

        // End of central directory record
        $eocd = pack(
            'VvvvvVVv',
            0x06054b50, 0, 0, $count, $count, strlen($central), strlen($data), 0,
        );

        return $data . $central . $eocd;
    }

    /** @return array{0:int,1:int} [time, date] in formato MS-DOS */
    private function dosDateTime(int $ts): array
    {
        $d = getdate($ts);
        if ($d['year'] < 1980) {
            return [0, (1 << 5) | 1];
        }
        $time = ($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2);
        $date = (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday'];
        return [$time, $date];
    }
}
