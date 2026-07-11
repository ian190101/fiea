<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\SystemSetting;
use App\Services\AccountingImportParser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountingImportController extends Controller
{
    private const SESSION_KEY = 'accounting_import_preview';

    public function preview(Request $request, AccountingImportParser $parser): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:5120'],
        ]);

        try {
            $rows = $parser->parse($data['file']);
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages([
                'file' => $exception->getMessage(),
            ]);
        }

        if (count($rows) === 0) {
            throw ValidationException::withMessages([
                'file' => 'El archivo no contiene filas validas para importar.',
            ]);
        }

        $preview = $this->buildPreview($rows);
        $request->session()->put(self::SESSION_KEY, $preview);

        return back()->with('success', 'Previsualizacion contable generada correctamente.');
    }

    public function apply(Request $request): RedirectResponse
    {
        $preview = $request->session()->get(self::SESSION_KEY);

        if (! is_array($preview) || empty($preview['rows'])) {
            throw ValidationException::withMessages([
                'file' => 'Primero debes cargar y previsualizar un archivo contable.',
            ]);
        }

        $settings = SystemSetting::query()->firstOrCreate(['id' => 1]);
        $applicableRows = collect($preview['rows'])->where('can_apply', true);

        if ($applicableRows->isEmpty()) {
            throw ValidationException::withMessages([
                'file' => 'No hay filas validas para aplicar.',
            ]);
        }

        DB::transaction(function () use ($request, $settings, $applicableRows) {
            foreach ($applicableRows as $row) {
                $invoice = Invoice::query()->where('code', $row['code'])->lockForUpdate()->first();

                if (! $invoice) {
                    continue;
                }

                $before = $invoice->only([
                    'accounting_status',
                    'accounting_note',
                    'total_dr',
                    'total_wodr',
                    'grand_total',
                    'balance_conciliation',
                ]);

                $payload = [
                    'accounting_status' => $row['accounting_status'],
                    'accounting_note' => $row['accounting_note'] ?: null,
                    'accounting_reviewed_by_id' => $request->user()?->id,
                    'accounting_reviewed_at' => now(),
                ];

                if ($settings->accounting_can_edit_summary) {
                    $payload['total_dr'] = round((float) $row['total_dr'], 2);
                    $payload['total_wodr'] = round((float) $row['total_wodr'], 2);
                    $payload['grand_total'] = round($payload['total_dr'] + $payload['total_wodr'], 2);
                    $payload['balance_conciliation'] = round((float) $row['balance_conciliation'], 2);
                }

                $invoice->fill($payload)->save();

                AuditLog::query()->create([
                    'user_id' => $request->user()?->id,
                    'action' => $settings->accounting_can_edit_summary ? 'accounting_import_applied_with_values' : 'accounting_import_reconciled',
                    'module' => 'accounting',
                    'auditable_type' => Invoice::class,
                    'auditable_id' => $invoice->id,
                    'ip_address' => $request->ip(),
                    'user_agent' => substr((string) $request->userAgent(), 0, 500),
                    'metadata' => [
                        'source' => 'accounting_import',
                        'before' => $before,
                        'after' => $invoice->only([
                            'accounting_status',
                            'accounting_note',
                            'total_dr',
                            'total_wodr',
                            'grand_total',
                            'balance_conciliation',
                        ]),
                    ],
                ]);
            }
        });

        $request->session()->forget(self::SESSION_KEY);

        return back()->with('success', $applicableRows->count().' filas contables aplicadas correctamente.');
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{summary: array<string, int>, rows: array<int, array<string, mixed>>}
     */
    private function buildPreview(array $rows): array
    {
        $settings = SystemSetting::query()->firstOrCreate(['id' => 1]);
        $codes = collect($rows)->pluck('code')->filter()->unique()->values();
        $invoices = Invoice::query()
            ->whereIn('code', $codes)
            ->get(['id', 'code', 'accounting_status', 'total_dr', 'total_wodr', 'grand_total', 'balance_conciliation'])
            ->keyBy('code');

        $previewRows = collect($rows)
            ->map(function (array $row, int $index) use ($invoices, $settings) {
                $invoice = $invoices->get($row['code']);
                $errors = $this->rowErrors($row, $invoice !== null, $settings->accounting_can_edit_summary);

                return [
                    'row' => $index + 2,
                    'code' => $row['code'],
                    'invoice_id' => $invoice?->id,
                    'current_status' => $invoice?->accounting_status,
                    'current_total_dr' => $invoice?->total_dr,
                    'current_total_wodr' => $invoice?->total_wodr,
                    'current_balance_conciliation' => $invoice?->balance_conciliation,
                    'accounting_status' => $row['accounting_status'],
                    'total_dr' => $row['total_dr'],
                    'total_wodr' => $row['total_wodr'],
                    'balance_conciliation' => $row['balance_conciliation'],
                    'accounting_note' => $row['accounting_note'],
                    'can_apply' => count($errors) === 0,
                    'errors' => $errors,
                    'mode' => $settings->accounting_can_edit_summary ? 'update_values' : 'reconcile_only',
                ];
            })
            ->values();

        return [
            'summary' => [
                'total' => $previewRows->count(),
                'valid' => $previewRows->where('can_apply', true)->count(),
                'invalid' => $previewRows->where('can_apply', false)->count(),
                'with_values' => $settings->accounting_can_edit_summary ? $previewRows->where('can_apply', true)->count() : 0,
            ],
            'rows' => $previewRows->all(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function rowErrors(array $row, bool $invoiceExists, bool $canEditValues): array
    {
        $errors = [];

        if (! $invoiceExists) {
            $errors[] = 'No existe una invoice con este codigo.';
        }

        if (! in_array($row['accounting_status'], ['pending', 'reconciled', 'flagged'], true)) {
            $errors[] = 'Estado contable invalido.';
        }

        if ($canEditValues) {
            foreach (['total_dr', 'total_wodr', 'balance_conciliation'] as $field) {
                if ($row[$field] === null || $row[$field] < 0) {
                    $errors[] = 'Los montos DR, WODR y balance deben ser numericos y no negativos.';
                    break;
                }
            }
        }

        return $errors;
    }
}
