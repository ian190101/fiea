<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\ActualExpense;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\TripPhase;
use App\Models\User;
use App\Services\Finance\ControlQuery;
use App\Services\Security\ScopeService;
use App\Support\Decimal as D;
use App\Support\MoneyPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

final class AlertQuery
{
    private User $actor;

    public function for(User $actor, array $filters): array
    {
        $this->actor = $actor;

        return $this->payload($filters);
    }

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
     * @param  array{type: string|null, severity: string|null, q: string|null}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function alerts(array $filters): Collection
    {
        $stream = LazyCollection::make(function () use ($filters) {
            foreach (['invoice' => 'invoiceAlerts', 'accounting' => 'accountingAlerts', 'receipt' => 'receiptAlerts',
                'email' => 'emailAlerts', 'trip' => 'tripAlerts', 'control' => 'controlAlerts'] as $type => $method) {
                $family = $type === 'control' ? 'accounting' : $type;
                if (($filters['type'] ?? null) && $filters['type'] !== $family) {
                    continue;
                }
                $permission = ['invoice' => 'invoices', 'accounting' => 'accounting', 'receipt' => 'receipts',
                    'email' => 'invoice_emails', 'trip' => 'trip_phases'][$family];
                if ($this->actor->hasAnyPermission([$permission.'.view', $permission.'.manage'])) {
                    yield from $this->{$method}();
                }
            }
        });
        $filtered = $stream
            ->when($filters['type'], fn (LazyCollection $alerts, string $type) => $alerts->where('type', $type))
            ->when($filters['severity'], fn (LazyCollection $alerts, string $severity) => $alerts->where('severity', $severity))
            ->when($filters['q'], function (LazyCollection $alerts, string $query) {
                $needle = mb_strtolower($query);

                return $alerts->filter(function (array $alert) use ($needle) {
                    return str_contains(mb_strtolower($alert['title'].' '.$alert['description'].' '.$alert['entity']), $needle);
                });
            });
        $rank = ['critical' => 0, 'warning' => 1, 'info' => 2];
        $compare = fn (array $a, array $b): int => ($rank[$a['severity']] <=> $rank[$b['severity']])
            ?: (($a['date'] ?? '9999-12-31') <=> ($b['date'] ?? '9999-12-31'));
        // Los filtros preceden al límite; se retienen como máximo 150 resultados en memoria.
        $top = [];
        foreach ($filtered as $alert) {
            $top[] = $alert;
            if (count($top) > 150) {
                usort($top, $compare);
                array_pop($top);
            }
        }
        usort($top, $compare);

        return collect($top);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function invoiceAlerts(): LazyCollection
    {
        return app(ScopeService::class)->constrain(Invoice::query(), $this->actor, 'invoices.trip_phase_id')
            ->with('tripPhase.project:id,code,name')
            ->where('status', '!=', 'void')
            ->where(function ($query) {
                $query->where('status', 'draft')
                    ->orWhereNull('pdf_file_id');
            })
            ->lazyById(250)
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
    private function accountingAlerts(): LazyCollection
    {
        $states = app(AccountingQuery::class)->states($this->actor);

        return app(ScopeService::class)->constrain(Invoice::query(), $this->actor, 'invoices.trip_phase_id')
            ->with('tripPhase.project:id,code,name')
            ->where('status', '!=', 'void')
            ->lazyById(250)
            ->map(function (Invoice $invoice) use ($states): Invoice {
                $invoice->accounting_status = $invoice->billing_case_id === null ? $invoice->accounting_status : ($states[$invoice->billing_case_id] ?? 'pending');

                return $invoice;
            })
            ->filter(fn (Invoice $invoice): bool => in_array($invoice->accounting_status, ['pending', 'flagged'], true))
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
    private function receiptAlerts(): LazyCollection
    {
        return app(ScopeService::class)->constrain(ActualExpense::query(), $this->actor, 'actual_expenses.trip_phase_id')
            ->with(['tripPhase.project:id,code,name'])
            ->doesntHave('receipts')
            ->select(['id', 'trip_phase_id', 'description', 'real_total', 'reported_at'])->lazyById(250)
            ->map(fn (ActualExpense $expense) => $this->alert(
                'receipt',
                'warning',
                'Gasto real sin recibo',
                'Debe adjuntarse el comprobante digital para completar respaldo.',
                "{$expense->description} - {$expense->tripPhase?->project?->code} - ".MoneyPresenter::format((string) $expense->real_total),
                route('receipts.index'),
                $expense->reported_at?->toDateString(),
            ));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function emailAlerts(): LazyCollection
    {
        return EmailLog::query()
            ->whereHas('invoice', fn (Builder $q) => app(ScopeService::class)->constrain($q, $this->actor, 'invoices.trip_phase_id'))
            ->with('invoice:id,code,trip_phase_id')
            ->whereIn('status', ['failed', 'unknown'])
            ->lazyById(250)
            ->map(fn (EmailLog $log) => $this->alert(
                'email',
                'critical',
                $log->status === 'unknown' ? 'Entrega de correo por revisar' : 'Correo de invoice fallido',
                $log->error_message ? 'Referencia: '.$log->error_message
                    : 'El envío automático no pudo completarse.',
                $log->invoice?->code ?? 'Invoice sin referencia',
                route('invoice-emails.index'),
                $log->created_at?->toDateString(),
            ));
    }

    private function controlAlerts(): LazyCollection
    {
        return LazyCollection::make(function () {
            $cursor = null;
            do {
                $page = app(ControlQuery::class)->rowsForYear($this->actor, (int) date('Y'), $cursor);
                yield from $page['rows'];
                $cursor = $page['next_cursor'];
            } while ($cursor !== null);
        })->filter(fn (array $row) => ($row['difference'] !== null && D::compare($row['difference'], '0') !== 0)
            || in_array($row['eia']['status'], ['PENDIENTE', 'NO PAGADO'], true)
            || in_array($row['fiea']['status'], ['PENDIENTE', 'NO PAGADO'], true))
            ->map(fn (array $row) => $this->alert(
                'accounting', 'warning', 'Control financiero pendiente',
                $row['difference'] === null ? 'Falta conciliación o pago.'
                    : 'Diferencia registro menos contabilidad: '.MoneyPresenter::format($row['difference']),
                implode(' & ', $row['invoice_codes']), route('accounting.control'), null,
            ));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function tripAlerts(): LazyCollection
    {
        return app(ScopeService::class)->tripPhases($this->actor)
            ->with(['project:id,code,name', 'team:id,name'])
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->whereDate('starts_on', '>=', now()->toDateString())
            ->whereDate('starts_on', '<=', now()->addDays(14)->toDateString())
            ->where(function ($query) {
                $query->whereNull('draft_pdf_file_id')
                    ->orWhere('status', 'draft');
            })
            ->select(['id', 'project_id', 'team_id', 'phase', 'starts_on', 'status', 'draft_pdf_file_id'])->lazyById(250)
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
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function remember(string $key, int $seconds, callable $callback): mixed
    {
        return $callback();
    }
}
