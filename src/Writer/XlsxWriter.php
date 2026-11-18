<?php

declare(strict_types=1);

namespace CkanScheduler\Writer;

use CkanScheduler\Exception\ExtractionException;

/**
 * Generatore XLSX (Office Open XML SpreadsheetML) senza dipendenze esterne.
 *
 * Non richiede Composer n� PhpSpreadsheet n� l'estensione ext-zip:
 * l'archivio ZIP viene costruito in PHP puro. Se ext-zlib � disponibile
 * (praticamente sempre) i file interni vengono compressi con DEFLATE,
 * altrimenti vengono salvati senza compressione (STORE).
 *
 * Caratteristiche del file prodotto:
 *  - un solo foglio, con nome derivato dal nome della risorsa
 *  - riga di intestazione in grassetto, bloccata (freeze pane) e con filtro
 *  - larghezza colonne stimata in base al contenuto
 *  - tipi di cella: numeri come numeri, booleani come booleani,
 *    NULL come cella vuota, tutto il resto come testo
 *  - stringhe numeriche con zeri iniziali (CAP, codici ISTAT, &) e numeri
 *    oltre 15 cifre restano TESTO, per non perdere informazioni
 */
final class XlsxWriter
{
    /** Limiti strutturali di Excel. */
    private const MAX_ROWS        = 1_048_576;
    private const MAX_COLS        = 16_384;
    private const MAX_CELL_CHARS  = 32_767;
    private const MAX_COL_WIDTH   = 60;
    private const MIN_COL_WIDTH   = 8;

    /** @var array<string,int> testo � indice nella shared strings table */
    private array $sharedIndex = [];
    /** @var list<string> */
    private array $sharedList  = [];
    private int   $sharedCount = 0;

    /**
     * @param list<array<string,mixed>> $rows  Result set (righe associative).
     * @return string Contenuto binario del file .xlsx
     */
    public function render(array $rows, string $sheetName = 'Dati'): string
    {
        $this->sharedIndex = [];
        $this->sharedList  = [];
        $this->sharedCount = 0;

        $headers = $rows === [] ? [] : array_keys($rows[0]);

        if (count($rows) + 1 > self::MAX_ROWS) {
            throw new ExtractionException(
                'Il risultato ha ' . count($rows) . ' righe: supera il limite XLSX di '
                . (self::MAX_ROWS - 1) . ' righe di dati.'
            );
        }
        if (count($headers) > self::MAX_COLS) {
            throw new ExtractionException(
                'Il risultato ha ' . count($headers) . ' colonne: supera il limite XLSX di '
                . self::MAX_COLS . '.'
            );
        }

        $sheetXml = $this->buildSheet($headers, $rows);

        $files = [
            '[Content_Types].xml'        => $this->contentTypes(),
            '_rels/.rels'                => $this->rootRels(),
            'docProps/app.xml'           => $this->appProps(),
            'docProps/core.xml'          => $this->coreProps(),
            'xl/workbook.xml'            => $this->workbook($this->sanitizeSheetName($sheetName)),
            'xl/_rels/workbook.xml.rels' => $this->workbookRels(),
            'xl/styles.xml'              => $this->styles(),
            'xl/sharedStrings.xml'       => $this->sharedStrings(),
            'xl/worksheets/sheet1.xml'   => $sheetXml,
        ];

        return (new ZipBuilder())->build($files);
    }

    //  Foglio di lavoro 

    /**
     * @param list<string|int>          $headers
     * @param list<array<string,mixed>> $rows
     */
    private function buildSheet(array $headers, array $rows): string
    {
        $colCount = count($headers);
        $letters  = [];
        $widths   = [];
        for ($c = 0; $c < $colCount; $c++) {
            $letters[$c] = self::columnLetter($c);
            $widths[$c]  = self::MIN_COL_WIDTH;
        }

        $body = [];

        // Riga 1: intestazioni (stile 1 = grassetto)
        if ($colCount > 0) {
            $cells = [];
            foreach ($headers as $c => $name) {
                $text      = (string) $name;
                $cells[]   = '<c r="' . $letters[$c] . '1" t="s" s="1"><v>'
                           . $this->sharedString($text) . '</v></c>';
                $widths[$c] = max($widths[$c], $this->displayLength($text) + 2);
            }
            $body[] = '<row r="1">' . implode('', $cells) . '</row>';
        }

        // Righe dati
        $r = 1;
        foreach ($rows as $row) {
            $r++;
            $cells  = [];
            $values = array_values($row);
            for ($c = 0; $c < $colCount; $c++) {
                $value = $values[$c] ?? null;
                [$xml, $len] = $this->cell($letters[$c] . $r, $value);
                if ($xml !== '') {
                    $cells[] = $xml;
                }
                if ($len > $widths[$c]) {
                    $widths[$c] = $len;
                }
            }
            $body[] = '<row r="' . $r . '">' . implode('', $cells) . '</row>';
        }

        $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
              . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';

        if ($colCount > 0) {
            $lastRef = $letters[$colCount - 1] . max(1, $r);
            $xml .= '<dimension ref="A1:' . $lastRef . '"/>';
            // Blocca la riga di intestazione
            $xml .= '<sheetViews><sheetView workbookViewId="0">'
                  . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
                  . '<selection pane="bottomLeft" activeCell="A2" sqref="A2"/>'
                  . '</sheetView></sheetViews>';
            $xml .= '<sheetFormatPr defaultRowHeight="15"/>';
            $xml .= '<cols>';
            foreach ($widths as $c => $w) {
                $w    = min(self::MAX_COL_WIDTH, $w);
                $n    = $c + 1;
                $xml .= '<col min="' . $n . '" max="' . $n . '" width="' . $w . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        } else {
            $xml .= '<dimension ref="A1"/>';
            $xml .= '<sheetViews><sheetView workbookViewId="0"/></sheetViews>';
            $xml .= '<sheetFormatPr defaultRowHeight="15"/>';
        }

        $xml .= '<sheetData>' . implode('', $body) . '</sheetData>';

        if ($colCount > 0) {
            $xml .= '<autoFilter ref="A1:' . $letters[$colCount - 1] . max(1, $r) . '"/>';
        }

        $xml .= '<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>';
        $xml .= '</worksheet>';

        return $xml;
    }

    /**
     * Restituisce [xml della cella, lunghezza stimata per la larghezza colonna].
     * Le celle NULL non vengono scritte (cella vuota).
     *
     * @return array{0:string,1:int}
     */
    private function cell(string $ref, mixed $value): array
    {
        if ($value === null) {
            return ['', 0];
        }

        if (is_bool($value)) {
            return ['<c r="' . $ref . '" t="b"><v>' . ($value ? '1' : '0') . '</v></c>', 6];
        }

        if (is_int($value) || (is_float($value) && is_finite($value))) {
            $num = $this->formatNumber($value);
            return ['<c r="' . $ref . '"><v>' . $num . '</v></c>', strlen($num) + 2];
        }

        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        $text = (string) $value;

        // PDO pgsql restituisce spesso numeric/bigint come stringa:
        // li convertiamo in numero solo se la conversione � senza perdita.
        if ($this->isSafeNumericString($text)) {
            return ['<c r="' . $ref . '"><v>' . $text . '</v></c>', strlen($text) + 2];
        }

        // Booleani PostgreSQL restituiti come 't' / 'f' non vengono toccati
        // (potrebbero essere testo legittimo): restano stringhe.

        $text = self::toUtf8($text);
        if (self::utf8Length($text) > self::MAX_CELL_CHARS) {
            $text = self::utf8Substr($text, self::MAX_CELL_CHARS);
        }

        return [
            '<c r="' . $ref . '" t="s"><v>' . $this->sharedString($text) . '</v></c>',
            $this->displayLength($text) + 2,
        ];
    }

    private function isSafeNumericString(string $s): bool
    {
        // Solo forma decimale semplice: -123, 45.67, 0.5 (niente esponenti, spazi, '+')
        if (!preg_match('/^-?(0|[1-9]\d*)(\.\d+)?$/', $s)) {
            return false;
        }
        // Oltre 15 cifre significative Excel perde precisione � resta testo
        $digits = strlen(ltrim(str_replace(['-', '.'], '', $s), '0'));
        return $digits <= 15;
    }

    private function formatNumber(int|float $n): string
    {
        if (is_int($n)) {
            if (abs($n) >= 1_000_000_000_000_000) {
                // > 15 cifre: Excel non lo rappresenterebbe esattamente
                return (string) (float) $n;
            }
            return (string) $n;
        }
        $s = rtrim(rtrim(sprintf('%.15F', $n), '0'), '.');
        // sprintf %F non usa esponenti; per valori molto grandi/piccoli usa la repr. PHP
        if ($s === '' || $s === '-0' || strlen($s) > 30) {
            $s = var_export($n, true);
        }
        return $s === '-0' ? '0' : $s;
    }

    private function displayLength(string $text): int
    {
        // Considera solo la prima riga per i testi multilinea
        $firstLine = strtok($text, "\n");
        return self::utf8Length(self::toUtf8($firstLine === false ? '' : $firstLine));
    }

    //  Shared strings 

    private function sharedString(string $text): int
    {
        $this->sharedCount++;
        if (!isset($this->sharedIndex[$text])) {
            $this->sharedIndex[$text] = count($this->sharedList);
            $this->sharedList[]       = $text;
        }
        return $this->sharedIndex[$text];
    }

    private function sharedStrings(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
             . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
             . ' count="' . $this->sharedCount . '" uniqueCount="' . count($this->sharedList) . '">';
        foreach ($this->sharedList as $text) {
            $escaped = self::xml($text);
            // Preserva spazi iniziali/finali e a capo
            $space = ($text !== trim($text) || str_contains($text, "\n")) ? ' xml:space="preserve"' : '';
            $xml  .= '<si><t' . $space . '>' . $escaped . '</t></si>';
        }
        return $xml . '</sst>';
    }

    //  Parti statiche del pacchetto 

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private function appProps(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">'
            . '<Application>CKAN Scheduler</Application>'
            . '</Properties>';
    }

    private function coreProps(): string
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
            . ' xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/"'
            . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:creator>CKAN Scheduler</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    private function workbook(string $sheetName): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<bookViews><workbookView/></bookViews>'
            . '<sheets><sheet name="' . self::xml($sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">'
            . "'" . self::xml(str_replace("'", "''", $sheetName)) . "'!\$A\$1"
            . '</definedName></definedNames>'
            . '</workbook>';
    }

    private function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
            . '</Relationships>';
    }

    /** Stile 0 = normale, stile 1 = intestazione in grassetto con sfondo grigio chiaro. */
    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2">'
            .   '<font><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
            .   '<font><b/><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            .   '<fill><patternFill patternType="none"/></fill>'
            .   '<fill><patternFill patternType="gray125"/></fill>'
            .   '<fill><patternFill patternType="solid"><fgColor rgb="FFE7E6E6"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2">'
            .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .   '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    //  Utility 

    /** 0 � A, 25 � Z, 26 � AA, & */
    public static function columnLetter(int $index): string
    {
        $letters = '';
        $n = $index + 1;
        while ($n > 0) {
            $mod     = ($n - 1) % 26;
            $letters = chr(65 + $mod) . $letters;
            $n       = intdiv($n - 1, 26);
        }
        return $letters;
    }

    /** Nome foglio valido per Excel: max 31 caratteri, senza  [ ] : * ? / \ */
    private function sanitizeSheetName(string $name): string
    {
        $name = self::toUtf8($name);
        $name = preg_replace('/[\[\]:*?\/\\\\]/u', '_', $name) ?? 'Dati';
        $name = trim($name, " '");
        if ($name === '') {
            $name = 'Dati';
        }
        return self::utf8Substr($name, 31);
    }

    /** Escape XML + rimozione dei caratteri di controllo non ammessi in XML 1.0. */
    private static function xml(string $s): string
    {
        $s = self::toUtf8($s);
        $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s) ?? '';
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    //  Helper UTF-8 senza ext-mbstring 
    // Usano solo PCRE (sempre presente in PHP), cos� il writer funziona
    // anche su server dove l'estensione mbstring non � installata.

    /** Se la stringa non � UTF-8 valido la tratta come ISO-8859-1 e la converte. */
    private static function toUtf8(string $s): string
    {
        if ($s === '' || preg_match('//u', $s) === 1) {
            return $s;
        }
        return preg_replace_callback(
            '/[\x80-\xFF]/',
            static fn (array $m): string => chr(0xC0 | (ord($m[0]) >> 6)) . chr(0x80 | (ord($m[0]) & 0x3F)),
            $s,
        ) ?? '';
    }

    /** Numero di caratteri (code point) di una stringa UTF-8 valida. */
    private static function utf8Length(string $s): int
    {
        if ($s === '') {
            return 0;
        }
        return (int) preg_match_all('/./su', $s);
    }

    /** Primi $length caratteri di una stringa UTF-8 valida. */
    private static function utf8Substr(string $s, int $length): string
    {
        if (preg_match('/^.{0,' . $length . '}/su', $s, $m) === 1) {
            return $m[0];
        }
        return $s;
    }
}
