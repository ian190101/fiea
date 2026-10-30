<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Data\Financial\ControlImportRow;
use App\Support\Decimal as D;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

final class ControlWorkbookParser
{
    private const MAX_ZIP = 5242880;

    private const MAX_UNPACKED = 52428800;

    private const MAX_XML = 20971520;

    /** @return list<ControlImportRow> */
    public function parse(UploadedFile $file, string $sheet): array
    {
        abort_unless(preg_match('/^20\d{2}$/D', $sheet) === 1, 422);
        abort_unless($file->getSize() <= self::MAX_ZIP
            && $file->getClientOriginalExtension() === 'xlsx', 422);
        $zip = new ZipArchive;
        abort_unless($zip->open($file->getRealPath()) === true, 422, 'Libro XLSX inválido.');
        try {
            $this->checkArchive($zip);
            $workbook = $this->xml($zip, 'xl/workbook.xml');
            $relations = $this->xml($zip, 'xl/_rels/workbook.xml.rels');
            $sheetId = null;
            foreach ($workbook->xpath('//*[local-name()="sheet"]') ?: [] as $entry) {
                if ((string) $entry['name'] === $sheet) {
                    $attrs = $entry->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                    $sheetId = (string) $attrs['id'];
                    break;
                }
            }
            abort_unless($sheetId !== null, 422, 'Hoja no encontrada.');
            $target = null;
            foreach ($relations->xpath('//*[local-name()="Relationship"]') ?: [] as $relation) {
                abort_unless((string) $relation['TargetMode'] !== 'External', 422);
                if ((string) $relation['Id'] === $sheetId) {
                    $target = (string) $relation['Target'];
                    break;
                }
            }
            abort_unless($target !== null, 422);
            $path = str_starts_with($target, '/xl/') ? ltrim($target, '/') : 'xl/'.ltrim($target, '/');
            abort_unless(! str_contains($path, '..') && str_starts_with($path, 'xl/worksheets/'), 422);
            $shared = $this->sharedStrings($zip);
            $date1904 = in_array((string) ($workbook->workbookPr['date1904'] ?? ''), ['1', 'true'], true);
            $worksheet = $this->xml($zip, $path);
            $rows = [];
            $headers = [];
            $main = $worksheet;
            $mergeRefs = $this->mergeReferences($main);
            $anchors = [];
            foreach ($main->sheetData->row as $xmlRow) {
                $rowNo = (int) $xmlRow['r'];
                abort_unless($rowNo <= 5002, 422, 'Demasiadas filas.');
                $cells = [];
                foreach ($xmlRow->c as $cell) {
                    $reference = (string) $cell['r'];
                    $column = preg_replace('/\d+$/', '', $reference);
                    abort_unless($this->columnNumber($column) <= 100, 422, 'Demasiadas columnas.');
                    $type = (string) $cell['t'];
                    $formula = isset($cell->f) ? (string) $cell->f : null;
                    $cached = isset($cell->v) ? (string) $cell->v : null;
                    $value = $type === 'inlineStr' ? (string) $cell->is->t : $cached;
                    if ($type === 's' && $cached !== null) {
                        $value = $shared[(int) $cached] ?? null;
                    }
                    $cells[$column] = ['reference' => $reference, 'type' => $type, 'formula' => $formula,
                        'cached_value' => $cached, 'value' => $value, 'date1904' => $date1904];
                    $anchors[$reference] = $cells[$column];
                }
                foreach ($mergeRefs[$rowNo] ?? [] as $column => $origin) {
                    if (! isset($cells[$column]) && isset($anchors[$origin])) {
                        $cells[$column] = $anchors[$origin] + ['merged_from' => $origin];
                    }
                }
                if ($rowNo === 2) {
                    foreach ($cells as $column => $cell) {
                        $headers[$column] = (string) ($cell['value'] ?? '');
                    }
                    abort_unless($this->header($headers['A'] ?? '') === 'STATUS'
                        && $this->header($headers['B'] ?? '') === 'INVOICE', 422, 'Encabezado de control inválido.');

                    continue;
                }
                if ($rowNo < 3) {
                    continue;
                }
                foreach (['case' => ['A', 'Z'], 'control_group' => ['AB', 'AH']] as $section => [$start,$end]) {
                    $part = [];
                    for ($i = $this->columnNumber($start); $i <= $this->columnNumber($end); $i++) {
                        $name = $this->columnName($i);
                        if (isset($cells[$name])) {
                            $part[$name] = $cells[$name] + ['header' => $headers[$name] ?? ''];
                        }
                    }
                    if (! $this->hasValue($part)) {
                        continue;
                    }
                    [$normalized,$errors] = $this->normalize($section, $part, $sheet, $date1904);
                    $rows[] = new ControlImportRow($section, $rowNo, $part, $normalized, $errors);
                }
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    private function checkArchive(ZipArchive $zip): void
    {
        abort_unless($zip->numFiles <= 128, 422, 'Libro demasiado grande.');
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->statIndex($i);
            abort_unless(is_array($entry), 422);
            $name = $entry['name'];
            abort_unless(! str_contains($name, '..') && ! str_starts_with($name, '/')
                && ! str_contains($name, '\\') && ! preg_match('/^[A-Za-z]:/', $name), 422);
            abort_unless(! str_ends_with(strtolower($name), '.bin')
                && ! str_contains(strtolower($name), 'externallink'), 422);
            $total += $entry['size'];
            abort_unless($total <= self::MAX_UNPACKED && (! str_ends_with($name, '.xml') || $entry['size'] <= self::MAX_XML), 422);
            abort_unless($entry['size'] === 0 || (($entry['comp_size'] ?? 0) > 0
                && $entry['size'] <= $entry['comp_size'] * 100), 422, 'Compresión excesiva.');
            abort_unless(($entry['encryption_method'] ?? 0) === 0, 422);
            $opsys = 0;
            $attributes = 0;
            $zip->getExternalAttributesIndex($i, $opsys, $attributes);
            $mode = $attributes >> 16;
            abort_unless(($mode & 0170000) !== 0120000, 422);
            $contents = $zip->getFromIndex($i);
            abort_unless($contents !== false && strlen($contents) === (int) $entry['size'], 422,
                'Entrada ZIP ilegible o incompleta.');
            unset($contents);
        }
    }

    private function xml(ZipArchive $zip, string $name): SimpleXMLElement
    {
        $stat = $zip->statName($name);
        abort_unless($stat !== false && $stat['size'] <= self::MAX_XML, 422);
        $contents = $zip->getFromName($name);
        abort_unless($contents !== false, 422);

        return $this->safeXml($contents);
    }

    private function safeXml(string $xml): SimpleXMLElement
    {
        if (strlen($xml) > 20 * 1024 * 1024 || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            throw new \InvalidArgumentException('El XML contiene estructuras no permitidas.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $result = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
            if (! $result instanceof SimpleXMLElement) {
                throw new \InvalidArgumentException('La estructura XML no es válida.');
            }

            return $result;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function sharedStrings(ZipArchive $zip): array
    {
        if ($zip->statName('xl/sharedStrings.xml') === false) {
            return [];
        }
        $xml = $this->xml($zip, 'xl/sharedStrings.xml');
        $result = [];
        foreach ($xml->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main')->si as $item) {
            $text = '';
            foreach ($item->xpath('.//*[local-name()="t"]') ?: [] as $node) {
                $text .= (string) $node;
            }
            $result[] = $text;
        }

        return $result;
    }

    private function normalize(string $section, array $part, string $sheet, bool $date1904): array
    {
        $values = [];
        $errors = [];
        foreach ($part as $column => $cell) {
            $value = $cell['value'];
            if ($cell['formula'] !== null && $cell['cached_value'] === null) {
                $errors[] = 'Fórmula sin valor cacheado en '.$cell['reference'];
            }
            $values[$column] = $value;
        }
        if ($section === 'case') {
            $codes = array_values(array_filter(array_map('trim', explode('&', (string) ($values['B'] ?? '')))));
            $values['codes'] = $codes;
            $values['lifecycle_status'] = trim((string) ($values['A'] ?? ''));
            $values['invoice_type'] = trim((string) ($values['C'] ?? ''));
            $values['billing_mode'] = str_contains(strtoupper((string) ($values['D'] ?? '').' '.(string) ($values['E'] ?? '')), 'NO INITIAL SOLO FINAL') ? 'final_only' : 'standard';
            if ($codes === []) {
                $errors[] = 'Falta código de invoice.';
            }
            foreach ($sheet === '2026' ? ['D', 'E', 'H', 'U', 'Y'] : ['D', 'E', 'H'] as $key) {
                if (($values[$key] ?? null) === null || $values[$key] === '') {
                    continue;
                }
                if ($this->pending($values[$key])) {
                    $values[$key] = null;

                    continue;
                }
                if (mb_strtoupper(trim((string) $values[$key])) === 'NO INITIAL SOLO FINAL') {
                    $values[$key] = null;

                    continue;
                }
                try {
                    $values[$key] = $this->date((string) $values[$key], $date1904);
                } catch (\Throwable) {
                    $values[$key] = null;
                    $errors[] = 'Fecha inválida en '.$key;
                }
            }
            foreach (['J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R'] as $key) {
                if (($values[$key] ?? null) !== null && $values[$key] !== '') {
                    if ($this->pending($values[$key])) {
                        $values[$key] = null;

                        continue;
                    }
                    try {
                        $values[$key] = $this->decimal((string) $values[$key], $part[$key]['type'] ?? '');
                    } catch (\Throwable) {
                        $values[$key] = null;
                        $errors[] = 'Importe inválido en '.$key;
                    }
                }
            }
            foreach ($sheet === '2025' ? ['S', 'T', 'U', 'V', 'W'] : ['T', 'W', 'X'] as $key) {
                if (($values[$key] ?? null) !== null && $values[$key] !== '') {
                    if ($this->pending($values[$key])) {
                        $values[$key] = null;

                        continue;
                    }
                    try {
                        $values[$key] = $this->decimal((string) $values[$key], $part[$key]['type'] ?? '');
                    } catch (\Throwable) {
                        $values[$key] = null;
                        $errors[] = 'Importe inválido en '.$key;
                    }
                }
            }
            if (isset($values['J'],$values['K'],$values['L'])
                && D::compare(D::add($values['J'], $values['K']), $values['L']) !== 0) {
                $errors[] = 'Initial no cuadra.';
            }
            foreach ($sheet === '2025' ? ['M'] : ['T', 'X'] as $column) {
                if (isset($values[$column]) && D::compare((string) $values[$column], '0') < 0) {
                    $errors[] = 'Un cobro negativo requiere una devolución documentada en tesorería: '.$column;
                }
            }
        } else {
            $values['codes'] = array_values(array_filter(array_map('trim', explode('&', (string) ($values['AB'] ?? '')))));
            foreach (['AD', 'AE', 'AF', 'AG', 'AH'] as $key) {
                if (($values[$key] ?? null) !== null && $values[$key] !== '') {
                    if ($this->pending($values[$key])) {
                        $values[$key] = null;

                        continue;
                    }
                    try {
                        $values[$key] = $this->decimal((string) $values[$key], $part[$key]['type'] ?? '');
                    } catch (\Throwable) {
                        $values[$key] = null;
                        $errors[] = 'Importe inválido en '.$key;
                    }
                }
            }
            if ($values['codes'] === []) {
                $errors[] = 'Faltan códigos de grupo.';
            }
        }

        return [$values, array_values(array_unique($errors))];
    }

    private function decimal(string $value, string $type): string
    {
        $value = trim($value);
        // El XML numérico conserva residuos IEEE; normalizar aquí no relaja el contrato decimal del dominio.
        if (in_array($type, ['n', ''], true) && strlen($value) <= 128
            && preg_match('/^-?\d+(?:\.\d+)?(?:[Ee][+-]?\d{1,3})?$/D', $value)) {
            return D::money((string) BigDecimal::of($value)->toScale(2, RoundingMode::HALF_EVEN));
        }
        if ($type !== 'n' && str_contains($value, ',')) {
            $locale = config('finance.control_import_locale');
            if (! in_array($locale, ['en_US', 'es_EC'], true)
                || preg_match('/^-?\d{1,3},\d{3}$/D', $value)) {
                throw new RuntimeException('Se requiere perfil regional inequívoco.');
            }
            if ($locale === 'en_US') {
                if (! preg_match('/^-?\d{1,3}(?:,\d{3})+\.\d{1,12}$/D', $value)) {
                    throw new RuntimeException('Formato regional inválido.');
                }
                $value = str_replace(',', '', $value);
            } else {
                if (! preg_match('/^-?\d{1,3}(?:\.\d{3})*,\d{1,12}$/D', $value)) {
                    throw new RuntimeException('Formato regional inválido.');
                }
                $value = str_replace(',', '.', str_replace('.', '', $value));
            }
        }
        D::valid($value);

        return D::money($value);
    }

    private function date(string $value, bool $date1904): string
    {
        $value = trim($value);
        $value = trim((string) preg_replace('/\s*\(NO INITIAL SOLO FINAL\)/i', '', $value));
        $value = (string) preg_replace('/^(?:INITIAL|FINAL|I|F)\s*-\s*/i', '', $value);
        $months = ['enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6,
            'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12];
        if (preg_match('/^(\d{1,2})(?:ero)?\s+de\s+([a-z]+)\s+(?:de\s+)?(20\d{2}|\d{2})$/iD', $value, $parts)
            && isset($months[strtolower($parts[2])])) {
            $value = $parts[1].'/'.$months[strtolower($parts[2])].'/'.(strlen($parts[3]) === 2 ? '20'.$parts[3] : $parts[3]);
        }
        if (preg_match('/^(\d{1,2})[\/-](\d{1,2})[\/-](20\d{2})$/D', $value, $parts)) {
            [$day,$month,$year] = array_map('intval', array_slice($parts, 1));
            if (! checkdate($month, $day, $year)) {
                throw new RuntimeException('Fecha inválida.');
            }

            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
        if (! preg_match('/^\d{1,6}(?:\.0+)?$/D', $value)) {
            throw new RuntimeException('Fecha inválida.');
        }
        $serial = (int) $value;
        if (! $date1904 && $serial === 60) {
            throw new RuntimeException('Día ficticio de Excel.');
        }
        $base = new DateTimeImmutable($date1904 ? '1904-01-01' : '1899-12-31');
        $days = $date1904 ? $serial : ($serial > 60 ? $serial - 1 : $serial);

        return $base->modify('+'.$days.' days')->format('Y-m-d');
    }

    private function pending(mixed $value): bool
    {
        return is_string($value) && mb_strtoupper(trim($value)) === 'PENDIENTE';
    }

    private function header(string $value): string
    {
        return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', str($value)->ascii()->toString())));
    }

    private function mergeReferences(SimpleXMLElement $main): array
    {
        $targets = [];
        $count = 0;
        foreach ($main->mergeCells->mergeCell ?? [] as $merge) {
            $range = (string) $merge['ref'];
            if (! preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/D', $range, $parts)) {
                continue;
            }
            $left = $this->columnNumber($parts[1]);
            $right = $this->columnNumber($parts[3]);
            $top = (int) $parts[2];
            $bottom = (int) $parts[4];
            abort_unless($left > 0 && $right <= 100 && $top > 0 && $bottom <= 5002
                && $left <= $right && $top <= $bottom, 422);
            $count += ($right - $left + 1) * ($bottom - $top + 1);
            abort_unless($count <= 200000, 422, 'Demasiadas celdas combinadas.');
            for ($row = $top; $row <= $bottom; $row++) {
                for ($column = $left; $column <= $right; $column++) {
                    $ref = $this->columnName($column).$row;
                    if ($ref !== $parts[1].$top) {
                        $targets[$row][$this->columnName($column)] = $parts[1].$top;
                    }
                }
            }
        }

        return $targets;
    }

    private function hasValue(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (($cell['value'] ?? null) !== null && trim((string) $cell['value']) !== '') {
                return true;
            }
        }

        return false;
    }

    private function columnNumber(string $column): int
    {
        $n = 0;
        foreach (str_split($column) as $char) {
            $n = $n * 26 + ord($char) - 64;
        }

        return $n;
    }

    private function columnName(int $number): string
    {
        $name = '';
        while ($number > 0) {
            $number--;
            $name = chr(65 + $number % 26).$name;
            $number = intdiv($number, 26);
        }

        return $name;
    }
}
