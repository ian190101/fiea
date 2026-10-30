<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\Financial\BudgetLineData;
use App\Data\Financial\CalculationInput;
use App\Http\Requests\UpdateBudgetRequest;
use App\Models\BillingCase;
use App\Models\TripPhase;
use App\Services\Finance\BudgetService;
use App\Services\Finance\FinancialDocumentPresenter;
use App\Services\Finance\FinancialSnapshotBuilder;
use App\Services\Finance\InvoiceCalculatorRegistry;
use App\Services\Security\ScopeService;
use App\Support\Decimal as D;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class BudgetController extends Controller
{
    public function show(Request $request, TripPhase $tripPhase): Response
    {
        $filters = $request->validate([
            'invoice_type' => ['sometimes', Rule::in(['IC', 'MAT'])],
            'stage' => ['sometimes', Rule::in(['initial', 'final'])],
            'view' => ['sometimes', Rule::in(['master', 'draft', 'ic_initial', 'ic_final', 'mat_initial', 'mat_final'])],
        ]);
        $type = $filters['invoice_type'] ?? 'IC';
        $stage = $filters['stage'] ?? 'initial';
        $view = $filters['view'] ?? 'draft';
        if (! in_array($view, ['master', 'draft'], true)) {
            [$viewType,$stage] = explode('_', $view);
            $type = strtoupper($viewType);
        }
        abort_unless($request->user()->hasAnyPermission(['budgets.view', 'budgets.manage'])
            && app(ScopeService::class)->permits($request->user(), (int) $tripPhase->id), 404);

        $case = BillingCase::query()->where('trip_phase_id', $tripPhase->id)
            ->where('invoice_type', $type)
            ->with(['lines' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
                'lines.versions' => fn ($q) => $q->where('stage', $stage),
                'lines.versions.cells' => fn ($q) => $q->orderBy('spent_on')])
            ->first();
        if (! $case) {
            return Inertia::render('Budget/Start', [
                'tripPhase' => ['id' => $tripPhase->id, 'phase' => $tripPhase->phase],
                'invoice_type' => $type, 'canCreate' => $request->user()->hasPermission('budgets.manage') && $tripPhase->status !== 'cancelled',
                'createUrl' => route('billing-cases.store', $tripPhase),
            ]);
        }

        $days = [];
        $start = CarbonImmutable::parse($tripPhase->starts_on);
        $end = CarbonImmutable::parse($tripPhase->ends_on);
        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $label = $day->equalTo($start) && $day->equalTo($end)
                ? 'Arrival to Quito / Departure to USA'
                : ($day->equalTo($start) ? 'Arrival to Quito' : ($day->equalTo($end) ? 'Departure to USA' : 'On Site'));
            $days[] = ['date' => $day->toDateString(), 'label' => $label];
        }

        $calculatorLines = [];
        $allCaptured = true;
        $lines = $case->lines->map(function ($line) use ($stage, $type, &$calculatorLines, &$allCaptured): array {
            $version = $line->versions->first();
            if (! $version || ! $version->is_captured) {
                $allCaptured = false;
            }
            $cells = $version?->cells->map(fn ($cell): array => [
                'date' => $cell->spent_on->toDateString(),
                'quantity' => $cell->quantity,
                'unit_cost' => $version->unit_cost,
                'receipt_number' => $cell->receipt_number,
                'total' => D::money(D::mul($cell->quantity, $version->unit_cost)),
            ])->all() ?? [];
            if ($version) {
                $raw = $type === 'IC'
                    ? D::sum(array_map(fn (array $cell): string => D::mul($cell['quantity'], $version->unit_cost), $cells))
                    : D::mul((string) $version->quantity, $version->unit_cost);
                $calculatorLines[] = new BudgetLineData($line->id, $line->bucket, $raw, $line->arranged_eligible);
            }

            return [
                'id' => $line->id, 'description_en' => $line->description_en,
                'description_es' => $line->description_es, 'unit' => $line->unit,
                'stage' => $stage, 'unit_cost' => $version?->unit_cost,
                'quantity' => $version?->quantity, 'receipt_number' => $version?->receipt_number,
                'cells' => $cells,
                'total' => $version?->is_captured ? D::money($raw) : null,
            ];
        })->all();

        $totals = null;
        if ($allCaptured) {
            $initialTotal = $stage === 'final' && $case->currentInitial?->snapshot
                ? (string) $case->currentInitial->grand_total : '0.00';
            $input = new CalculationInput($stage, $calculatorLines,
                (string) ($stage === 'initial' ? $case->bank_fee_initial : $case->bank_fee_final),
                $case->final_contingency, $initialTotal);
            $totals = get_object_vars(app(InvoiceCalculatorRegistry::class)->forType($type)->calculate($input));
        }

        $pointer = $stage === 'initial' ? $case->currentInitial : $case->currentFinal;
        $canUpdate = $request->user()->hasPermission(
            $stage === 'final' ? 'actual_expenses.manage' : 'budgets.manage')
            && ! in_array($case->lifecycle_status, ['CERRADO', 'ANULADO'], true)
            && $tripPhase->status !== 'cancelled'
            && ! in_array($pointer?->status, ['approved', 'sent', 'paid'], true);

        $presenter = app(FinancialDocumentPresenter::class);
        $snapshot = $pointer?->snapshot;
        if (! is_array($snapshot) && $allCaptured) {
            try {
                $snapshot = app(FinancialSnapshotBuilder::class)->build($case, $stage, null, true)['snapshot'];
            } catch (HttpException $e) {
                if ($e->getStatusCode() !== 422) {
                    throw $e;
                }
            }
        }
        if (! $canUpdate && is_array($pointer?->snapshot)) {
            $lines = array_map(static fn (array $line): array => $line + ['total' => D::money($line['raw_amount'])], $pointer->snapshot['lines']);
            $totals = $pointer->snapshot['totals'];
        }
        $editors = [];
        foreach (['IC', 'MAT'] as $optionType) {
            foreach (['initial', 'final'] as $optionStage) {
                $editors[] = ['value' => $optionType.':'.$optionStage, 'label' => $optionType.' '.ucfirst($optionStage),
                    'href' => route('budgets.show', ['tripPhase' => $tripPhase->id, 'invoice_type' => $optionType, 'stage' => $optionStage, 'view' => 'draft'])];
            }
        }
        $sheets = [['value' => 'master', 'label' => 'Master Template'], ['value' => 'draft', 'label' => 'Draft Budget']];
        foreach ($editors as $editor) {
            $sheets[] = ['value' => strtolower(str_replace(':', '_', $editor['value'])), 'label' => $editor['label']];
        }
        foreach ($sheets as &$sheet) {
            $sheet['href'] = route('budgets.show', ['tripPhase' => $tripPhase->id, 'view' => $sheet['value'], 'invoice_type' => $type, 'stage' => $stage]);
        }
        unset($sheet);

        return Inertia::render('Budget/Show', [
            'tripPhase' => ['id' => $tripPhase->id, 'label' => $tripPhase->project->name.' · '.$tripPhase->team->name],
            'budget' => ['version' => $case->version, 'days' => $days, 'lines' => $lines, 'totals' => $presenter->totals($totals, $type)],
            'invoice_type' => $type, 'stage' => $stage, 'view' => $view, 'sheets' => $sheets, 'editors' => $editors,
            'master' => ['metadata' => $snapshot['metadata'] ?? [], 'edit_links' => []],
            'invoicePreview' => is_array($snapshot) ? $presenter->snapshot($snapshot, $pointer?->code) : null,
            'urls' => ['update' => route('budgets.update', $tripPhase)],
            'can' => ['update' => $canUpdate],
        ]);
    }

    public function update(UpdateBudgetRequest $request, TripPhase $tripPhase, BudgetService $service): RedirectResponse
    {
        $service->update($request->user(), (int) $tripPhase->id, $request->data());

        return back()->with('success', 'Matriz guardada correctamente.');
    }
}
