<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Data\Financial\BudgetLineData;
use App\Data\Financial\CalculationInput;
use App\Data\Financial\InvoiceTotals;
use App\Models\AuditLog;
use App\Models\BillingCase;
use App\Models\ContactAssignment;
use App\Models\ContactPerson;
use App\Models\Invoice;
use App\Support\Decimal as D;
use Carbon\CarbonImmutable;

final class FinancialSnapshotBuilder
{
    public function __construct(private readonly InvoiceCalculatorRegistry $calculators) {}

    /** @return array{totals:InvoiceTotals,snapshot:array,initialInvoiceId:?int} */
    public function build(BillingCase $case, string $stage, ?int $contactPersonId = null, bool $draftComparison = false): array
    {
        abort_unless(in_array($stage, ['initial', 'final'], true), 422);
        $case->load(['tripPhase.project', 'tripPhase.team.chapter',
            'lines' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
            'lines.versions' => fn ($q) => $q->where('stage', $stage),
            'lines.versions.cells' => fn ($q) => $q->orderBy('spent_on')]);
        $phase = $case->tripPhase;
        abort_unless($phase && $phase->project && $phase->team, 422, 'Falta la cabecera del viaje.');
        abort_unless($case->lines->isNotEmpty(), 422, 'No hay líneas para emitir.');

        $contact = $this->billingContact($case, $contactPersonId);
        $metadata = [
            'team_name' => $phase->team->name,
            'project_name' => $phase->project->name,
            'volunteer_count' => (int) $phase->volunteer_count,
            'staff_count' => (int) $phase->staff_count,
            'starts_on' => $phase->starts_on->toDateString(),
            'ends_on' => $phase->ends_on->toDateString(),
            'invoice_name' => $contact->full_name,
            'address' => $contact->physical_address,
            'phone' => $contact->phone,
            'email' => $contact->email,
            'project_code' => $phase->project->code,
            'document_date' => $case->metadata['document_date'] ?? null,
        ];
        foreach ($metadata as $key => $value) {
            abort_unless($value !== null && $value !== '', 422, "Falta {$key} en la cabecera documental.");
        }
        $documentDate = CarbonImmutable::createFromFormat('!Y-m-d', (string) $metadata['document_date']);
        abort_unless($documentDate !== false
            && $documentDate->toDateString() === $metadata['document_date'], 422);

        $initial = null;
        if ($stage === 'final') {
            if ($case->billing_mode === 'standard') {
                $initial = Invoice::query()->whereKey($case->current_initial_id)
                    ->where('billing_case_id', $case->id)
                    ->whereIn('status', ['approved', 'sent', 'paid'])
                    ->whereNotNull('snapshot')->first();
                if (! $initial && $draftComparison) {
                    // La comparación del Draft nunca autoriza emitir un Final ni persiste un Initial ficticio.
                    $preview = $this->build($case->fresh(), 'initial', $contactPersonId);
                    $initial = new Invoice;
                    $initial->forceFill(['grand_total' => $preview['totals']->total, 'snapshot' => $preview['snapshot']]);
                }
                abort_unless($initial, 422, 'Final requiere Initial vigente y aprobado.');
            } else {
                abort_unless($case->billing_mode === 'final_only', 422);
            }
        } else {
            abort_unless($case->billing_mode !== 'final_only', 422,
                'Este caso se emite solamente como Final.');
        }

        $lines = [];
        $calculatorLines = [];
        $bases = ['dr' => '0', 'own' => '0', 'materials' => '0', 'transport' => '0', 'eligible' => '0'];
        $bucketRaw = ['dr' => [], 'own' => [], 'materials' => [], 'transport' => []];
        foreach ($case->lines as $line) {
            abort_unless(in_array($line->bucket, $case->invoice_type === 'IC' ? ['dr', 'own'] : ['materials', 'transport'], true), 422);
            $version = $line->versions->first();
            abort_unless($version && $version->is_captured && $version->stage === $stage, 422,
                'Hay líneas sin cantidades capturadas para esta etapa.');
            $cells = [];
            if ($case->invoice_type === 'IC') {
                abort_unless($version->quantity === null, 422);
                $rawAmounts = [];
                foreach ($version->cells as $cell) {
                    // Se conserva la plantilla histórica vacía al recortar fechas; nunca se oculta un gasto o comprobante.
                    if (($cell->spent_on->lt($phase->starts_on) || $cell->spent_on->gt($phase->ends_on))
                        && D::compare($cell->quantity, '0') === 0 && trim((string) $cell->receipt_number) === '') {
                        continue;
                    }
                    abort_unless($cell->spent_on->gte($phase->starts_on)
                        && $cell->spent_on->lte($phase->ends_on), 422);
                    abort_unless(D::compare($cell->quantity, '0') >= 0, 422);
                    $raw = D::mul($cell->quantity, $version->unit_cost);
                    $rawAmounts[] = $raw;
                    $cells[] = [
                        'date' => $cell->spent_on->toDateString(),
                        'quantity' => $cell->quantity,
                        'unit_cost' => $version->unit_cost,
                        'receipt_number' => $cell->receipt_number,
                        'raw_amount' => $raw,
                    ];
                }
                abort_unless($cells !== [], 422, 'Hay líneas IC sin celdas capturadas.');
                $rawAmount = D::sum($rawAmounts);
            } else {
                abort_unless($version->quantity !== null && $version->cells->isEmpty(), 422);
                abort_unless(D::compare($version->quantity, '0') >= 0, 422);
                $rawAmount = D::mul($version->quantity, $version->unit_cost);
            }
            abort_unless(D::compare($version->unit_cost, '0') >= 0, 422);
            D::money($rawAmount);
            $calculatorLines[] = new BudgetLineData((int) $line->id, $line->bucket, $rawAmount, $line->arranged_eligible);
            $bases[$line->bucket] = D::add($bases[$line->bucket], $rawAmount);
            $bucketRaw[$line->bucket][] = $rawAmount;
            if ($line->arranged_eligible) {
                $bases['eligible'] = D::add($bases['eligible'], $rawAmount);
            }
            $lines[] = [
                'id' => $line->id, 'catalog_key' => $line->catalog_key,
                'description_en' => $line->description_en, 'description_es' => $line->description_es,
                'unit' => $line->unit, 'section' => $line->section,
                'bucket' => $line->bucket, 'arranged_eligible' => $line->arranged_eligible,
                'unit_cost' => $version->unit_cost, 'quantity' => $version->quantity,
                'receipt_number' => $version->receipt_number, 'cells' => $cells,
                'raw_amount' => $rawAmount,
            ];
        }

        if ($stage === 'final' && $case->final_contingency !== null) {
            $note = $case->metadata['final_contingency_note'] ?? null;
            $author = $case->metadata['final_contingency_approved_by_id'] ?? null;
            abort_unless(is_string($note) && trim($note) !== '' && is_int($author)
                && AuditLog::query()->where('auditable_type', BillingCase::class)
                    ->where('auditable_id', $case->id)->where('action', 'final_contingency_approved')
                    ->where('user_id', $author)->exists(), 422,
                'El ajuste de contingencia requiere motivo y auditoría.');
        }
        $input = new CalculationInput($stage, $calculatorLines,
            (string) ($stage === 'initial' ? $case->bank_fee_initial : $case->bank_fee_final),
            $case->final_contingency, $initial?->grand_total ?? '0.00');
        $totals = $this->calculators->forType($case->invoice_type)->calculate($input);
        $adjustments = [];
        foreach ($bucketRaw as $bucket => $raws) {
            $roundedLines = array_map(static fn (string $raw): string => D::money($raw), $raws);
            $adjustments[$bucket] = D::money(D::sub(D::money(D::sum($raws)), D::sum($roundedLines)));
        }
        $groupedRaw = [...$bucketRaw['materials'], ...$bucketRaw['transport']];
        $adjustments['materials_transport'] = D::money(D::sub(
            D::money(D::sum($groupedRaw)),
            D::sum(array_map(static fn (string $raw): string => D::money($raw), $groupedRaw))
        ));
        $snapshot = [
            'calculation_version' => (string) config('finance.calculation_version'),
            'currency' => (string) config('finance.currency'),
            'type' => $case->invoice_type, 'stage' => $stage,
            'preview_only' => $draftComparison && $initial !== null && ! $initial->exists,
            'metadata' => $metadata, 'lines' => $lines,
            'bases' => $bases,
            'rates' => [
                'arranged' => (string) config('finance.ic_arranged_rate'),
                'purchase' => (string) config('finance.mat_purchase_rate'),
                'contingency' => (string) config('finance.contingency_rate'),
            ],
            'display_rounding_adjustment' => $adjustments,
            'totals' => get_object_vars($totals),
            'initial_invoice_id' => $initial?->id,
            'initial_total' => $initial?->grand_total ?? '0.00',
            'initial_snapshot' => $initial?->snapshot,
            'payment_terms' => config('finance.payment_terms'),
        ];

        return ['totals' => $totals, 'snapshot' => $snapshot, 'initialInvoiceId' => $initial?->id];
    }

    private function billingContact(BillingCase $case, ?int $contactPersonId): ContactPerson
    {
        $phase = $case->tripPhase;
        $assignments = ContactAssignment::query()->where('is_active', true)->where('is_billing_contact', true)
            ->where(fn ($q) => $q->where('team_id', $phase->team_id)
                ->orWhere(fn ($q) => $q->whereNull('team_id')->where('chapter_id', $phase->team->chapter_id)));
        if ($contactPersonId !== null) {
            $assignments->where('contact_person_id', $contactPersonId);
        }
        $ids = $assignments->pluck('contact_person_id')->unique()->values();
        abort_unless($ids->count() === 1, 422, 'Selecciona un contacto de facturación inequívoco.');

        return ContactPerson::query()->findOrFail($ids->first());
    }
}
