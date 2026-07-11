<?php

namespace App\Http\Controllers;

use App\Models\ActualExpense;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\TripPhase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class AlertController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        return Inertia::render('Alerts/Index', [
            'filters' => $filters,
            'alerts' => Inertia::defer(fn () => $this->payload($filters)['alerts'], 'alerts'),
            'summary' => Inertia::defer(fn () => $this->payload($filters)['summary'], 'alerts'),
            'types' => [
                ['value' => 'invoice', 'label' => 'Invoices'],
                ['value' => 'accounting', 'label' => 'Contabilidad'],
                ['value' => 'receipt', 'label' => 'Recibos'],
                ['value' => 'email', 'label' => 'Correos'],
                ['value' => 'trip', 'label' => 'Viajes'],
            ],
            'severities' => [
                ['value' => 'critical', 'label' => 'Critica'],
                ['value' => 'warning', 'label' => 'Advertencia'],
                ['value' => 'info', 'label' => 'Informativa'],
            ],
        ]);
    }

    /**
     * @return array{type: string|null, severity: string|null, q: string|null}
     */
    private function filters(Request $request): array
    {
        $data = validator($request->query(), [
            'type' => ['nullable', 'in:invoice,accounting,receipt,email,trip'],
            'severity' => ['nullable', 'in:critical,warning,info'],
            'q' => ['nullable', 'string', 'max:80'],
        ])->validate();

        return [
            'type' => $data['type'] ?? null,
            'severity' => $data['severity'] ?? null,
            'q' => $data['q'] ?? null,
        ];
    }

    /**
     * @param array{type: string|null, severity: string|null, q: string|null} $filters
     * @return array{alerts: array<int, array<string, mixed>>, summary: array<string, int>}
     */
    private function payload(array $filters): array
    {
        $cacheKey = md5(json_encode($filters));

        return $this->remember("payload:{$cacheKey}", 15, function () use ($filters) {
            $alerts = $this->alerts($filters);

            return [
                'alerts' => $alerts->values()->all(),
                'summary' => [
                    'total' => $alerts->count(),
                    'critical' => $alerts->where('severity', 'critical')->count(),
                    'warning' => $alerts->where('severity', 'warning')->count(),
                    'info' => $alerts->where('severity', 'info')->count(),
                ],
            ];
        });
    }

    /**
     * @param array{type: string|null, severity: string|null, q: string|null} $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function alerts(array $filters): Collection
    {
        return collect()
            ->merge($this->invoiceAlerts())
            ->merge($this->accountingAlerts())
            ->merge($this->receiptAlerts())
            ->merge($this->emailAlerts())
            ->merge($this->tripAlerts())
            ->when($filters['type'], fn (Collection $alerts, string $type) => $alerts->where('type', $type))
            ->when($filters['severity'], fn (Collection $alerts, string $severity) => $alerts->where('severity', $severity))
            ->when($filters['q'], function (Collection $alerts, string $query) {
                $needle = mb_strtolower($query);

                return $alerts->filter(function (array $alert) use ($needle) {
                    return str_contains(mb_strtolower($alert['title'].' '.$alert['description'].' '.$alert['entity']), $needle);
                });
            })
            ->sortBy([
                fn (array $alert) => ['critical' => 0, 'warning' => 1, 'info' => 2][$alert['severity']] ?? 3,
                fn (array $alert) => $alert['date'] ?? now()->addYears(10)->toDateString(),
            ])
            ->take(150)
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function invoiceAlerts(): Collection
    {
        return Invoice::query()
            ->with('tripPhase.project:id,code,name')
            ->where(function ($query) {
                $query->where('status', 'draft')
                    ->orWhereNull('pdf_file_id');
            })
            ->latest()
            ->limit(40)
            ->get()
            ->flatMap(function (Invoice $invoice) {
                $alerts = collect();
                $entity = "{$invoice->code} - {$invoice->tripPhase?->project?->code}";

                if ($invoice->status === 'draft') {
                    $alerts->push($this->alert(
                        'invoice',
                        'warning',
                        'Invoice pendiente de aprobacion',
                        'Debe revisarse y aprobarse antes de enviarla.',
                        $entity,
                        route('invoices.index'),
                        $invoice->created_at?->toDateString(),
                    ));
                }

                if ($invoice->pdf_file_id === null) {
                    $alerts->push($this->alert(
                        'invoice',
                        'info',
                        'PDF de invoice pendiente',
                        'La invoice todavia no tiene PDF generado.',
                        $entity,
                        route('invoices.index'),
                        $invoice->created_at?->toDateString(),
                    ));
                }

                return $alerts;
            });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function accountingAlerts(): Collection
    {
        return Invoice::query()
            ->with('tripPhase.project:id,code,name')
            ->whereIn('accounting_status', ['pending', 'flagged'])
            ->latest()
            ->limit(40)
            ->get()
            ->map(fn (Invoice $invoice) => $this->alert(
                'accounting',
                $invoice->accounting_status === 'flagged' ? 'critical' : 'warning',
                $invoice->accounting_status === 'flagged' ? 'Invoice observada por contabilidad' : 'Invoice pendiente de conciliacion',
                $invoice->accounting_status === 'flagged'
                    ? ($invoice->accounting_note ?: 'Contabilidad marco esta invoice para revision.')
                    : 'Falta registrar o confirmar la conciliacion contable.',
                "{$invoice->code} - {$invoice->tripPhase?->project?->code}",
                route('accounting.index'),
                $invoice->accounting_reviewed_at?->toDateString() ?? $invoice->created_at?->toDateString(),
            ));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function receiptAlerts(): Collection
    {
        return ActualExpense::query()
            ->with(['tripPhase.project:id,code,name'])
            ->doesntHave('receipts')
            ->latest('reported_at')
            ->limit(40)
            ->get(['id', 'trip_phase_id', 'description', 'real_total', 'reported_at'])
            ->map(fn (ActualExpense $expense) => $this->alert(
                'receipt',
                'warning',
                'Gasto real sin recibo',
                'Debe adjuntarse el comprobante digital para completar respaldo.',
                "{$expense->description} - {$expense->tripPhase?->project?->code} - $".number_format((float) $expense->real_total, 2),
                route('receipts.index'),
                $expense->reported_at?->toDateString(),
            ));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function emailAlerts(): Collection
    {
        return EmailLog::query()
            ->with('invoice:id,code,trip_phase_id')
            ->where('status', 'failed')
            ->latest()
            ->limit(40)
            ->get()
            ->map(fn (EmailLog $log) => $this->alert(
                'email',
                'critical',
                'Correo de invoice fallido',
                $log->error_message ?: 'El envio automatico no pudo completarse.',
                $log->invoice?->code ?? 'Invoice sin referencia',
                route('invoice-emails.index'),
                $log->created_at?->toDateString(),
            ));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function tripAlerts(): Collection
    {
        return TripPhase::query()
            ->with(['project:id,code,name', 'team:id,name'])
            ->whereDate('starts_on', '>=', now()->toDateString())
            ->whereDate('starts_on', '<=', now()->addDays(14)->toDateString())
            ->where(function ($query) {
                $query->whereNull('draft_pdf_file_id')
                    ->orWhere('status', 'draft');
            })
            ->orderBy('starts_on')
            ->limit(40)
            ->get(['id', 'project_id', 'team_id', 'phase', 'starts_on', 'status', 'draft_pdf_file_id'])
            ->map(fn (TripPhase $phase) => $this->alert(
                'trip',
                $phase->status === 'draft' ? 'critical' : 'warning',
                $phase->status === 'draft' ? 'Viaje proximo en borrador' : 'Draft budget PDF pendiente',
                $phase->status === 'draft'
                    ? 'La fase inicia pronto y todavia esta en borrador.'
                    : 'La fase inicia pronto y necesita su PDF de presupuesto.',
                "{$phase->project?->code} - {$phase->phase} - {$phase->team?->name}",
                route('trip-phases.index'),
                $phase->starts_on?->toDateString(),
            ));
    }

    /**
     * @return array{type: string, severity: string, title: string, description: string, entity: string, href: string, date: string|null}
     */
    private function alert(
        string $type,
        string $severity,
        string $title,
        string $description,
        string $entity,
        string $href,
        ?string $date,
    ): array {
        return compact('type', 'severity', 'title', 'description', 'entity', 'href', 'date');
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function remember(string $key, int $seconds, callable $callback): mixed
    {
        if (app()->environment('testing')) {
            return $callback();
        }

        return Cache::remember("fiea:alerts:{$key}", now()->addSeconds($seconds), $callback);
    }
}
