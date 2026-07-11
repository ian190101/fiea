<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class AccountingImportParser
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function parse(UploadedFile $file): array
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        return match ($extension) {
            'csv', 'txt' => $this->parseCsv($file->getRealPath()),
            'xlsx' => $this->parseXlsx($file->getRealPath()),
            default => throw new InvalidArgumentException('El archivo debe ser CSV o XLSX.'),
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseCsv(string $path): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException('No se pudo leer el archivo importado.');
        }

        $headers = null;
        $rows = [];

        while (($data = fgetcsv($handle)) !== false) {
            if ($headers === null) {
                $headers = $this->headers($data);

                continue;
            }

            $rows[] = $this->mapRow($headers, $data);
        }

        fclose($handle);

        return $this->cleanRows($rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseXlsx(string $path): array
    {
        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new RuntimeException('No se pudo abrir el XLSX importado.');
        }

        $sharedStrings = $this->sharedStrings($zip);
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if ($sheetXml === false) {
            throw new RuntimeException('El XLSX no contiene una primera hoja valida.');
        }

        $sheet = simplexml_load_string($sheetXml);

        if (! $sheet instanceof SimpleXMLElement) {
            throw new RuntimeException('No se pudo interpretar la primera hoja del XLSX.');
        }

        $rows = [];
        $headers = null;

        foreach ($sheet->sheetData->row as $row) {
            $values = $this->xlsxRowValues($row, $sharedStrings);

            if ($headers === null) {
                $headers = $this->headers($values);

                continue;
            }

            $rows[] = $this->mapRow($headers, $values);
        }

        return $this->cleanRows($rows);
    }

    /**
     * @return array<int, string>
     */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            return [];
        }

        $strings = simplexml_load_string($xml);

        if (! $strings instanceof SimpleXMLElement) {
            return [];
        }

        $values = [];

        foreach ($strings->si as $item) {
            if (isset($item->t)) {
                $values[] = (string) $item->t;

                continue;
            }

            $text = '';
            foreach ($item->r as $run) {
                $text .= (string) $run->t;
            }
            $values[] = $text;
        }

        return $values;
    }

    /**
     * @param SimpleXMLElement $row
     * @param array<int, string> $sharedStrings
     * @return array<int, string|null>
     */
    private function xlsxRowValues(SimpleXMLElement $row, array $sharedStrings): array
    {
        $values = [];

        foreach ($row->c as $cell) {
            $reference = (string) $cell['r'];
            $columnIndex = $this->columnIndex($reference);
            $type = (string) $cell['t'];
            $raw = isset($cell->v) ? (string) $cell->v : null;

            while (count($values) < $columnIndex) {
                $values[] = null;
            }

            $values[$columnIndex] = $type === 's' && $raw !== null
                ? ($sharedStrings[(int) $raw] ?? '')
                : $raw;
        }

        return $values;
    }

    private function columnIndex(string $reference): int
    {
        preg_match('/^[A-Z]+/i', $reference, $matches);
        $letters = strtoupper($matches[0] ?? 'A');
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return max($index - 1, 0);
    }

    /**
     * @param array<int, string|null> $headers
     * @return array<int, string>
     */
    private function headers(array $headers): array
    {
        return array_map(fn ($header) => $this->normalizeHeader((string) $header), $headers);
    }

    private function normalizeHeader(string $header): string
    {
        $header = str($header)->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();

        return match ($header) {
            'invoice', 'invoice_code', 'codigo', 'codigo_invoice', 'code' => 'code',
            'estado', 'estado_contable', 'accounting_status', 'status' => 'accounting_status',
            'dr', 'total_dr', 'dr_total' => 'total_dr',
            'wodr', 'total_wodr', 'wodr_total' => 'total_wodr',
            'balance', 'balance_conciliation', 'conciliacion', 'balance_conciliacion' => 'balance_conciliation',
            'nota', 'note', 'accounting_note', 'nota_contable' => 'accounting_note',
            default => $header,
        };
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, string|null> $values
     * @return array<string, mixed>
     */
    private function mapRow(array $headers, array $values): array
    {
        $row = [];

        foreach ($headers as $index => $header) {
            if ($header === '') {
                continue;
            }

            $row[$header] = trim((string) ($values[$index] ?? ''));
        }

        return [
            'code' => $row['code'] ?? '',
            'accounting_status' => $this->normalizeStatus($row['accounting_status'] ?? ''),
            'total_dr' => $this->number($row['total_dr'] ?? null),
            'total_wodr' => $this->number($row['total_wodr'] ?? null),
            'balance_conciliation' => $this->number($row['balance_conciliation'] ?? null),
            'accounting_note' => $row['accounting_note'] ?? null,
        ];
    }

    private function normalizeStatus(?string $status): ?string
    {
        $status = str((string) $status)->lower()->ascii()->trim()->toString();

        return match ($status) {
            'pending', 'pendiente' => 'pending',
            'reconciled', 'reconciliado', 'conciliado' => 'reconciled',
            'flagged', 'observado', 'observada' => 'flagged',
            default => $status ?: null,
        };
    }

    private function number(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = str_replace([',', '$', ' '], ['', '', ''], (string) $value);

        return is_numeric($normalized) ? round((float) $normalized, 2) : null;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function cleanRows(array $rows): array
    {
        return collect($rows)
            ->filter(fn (array $row) => $row['code'] !== '')
            ->values()
            ->all();
    }
}
