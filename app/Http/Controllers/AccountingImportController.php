<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AccountingReview;
use App\Models\BillingCase;
use App\Models\FinancialImport;
use App\Services\Finance\ControlImportService;
use App\Services\Security\ScopeService;
use App\Support\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class AccountingImportController extends Controller
{
    public function preview(Request $request, ControlImportService $service): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:5120'],
            'sheet' => ['required', 'in:2025,2026'],
            'locale' => ['nullable', 'in:en_US,es_EC'],
        ]);
        config()->set('finance.control_import_locale', $data['locale'] ?? null);
        $import = $service->preview($request->user(), $data['file'], $data['sheet']);
        $request->session()->put('financial_import_id', $import->id);

        return back()->with('success', 'Previsualización generada.');
    }

    public function buildPreview(Request $request, int $importId): array
    {
        $data = $request->validate(['import_cursor' => ['nullable', 'string', 'max:500']]);
        $import = FinancialImport::query()->where('actor_id', $request->user()->id)
            ->findOrFail($importId);
        $page = $import->rows()->whereNull('applied_at')->with(['cases.tripPhase', 'targetCase.tripPhase'])
            ->orderBy('id')->cursorPaginate(25, ['*'], 'import_cursor', $data['import_cursor'] ?? null)->withQueryString();
        $reviews = AccountingReview::query()->where('period_year', (int) $import->sheet)
            ->whereIn('project_id', $page->getCollection()->flatMap(fn ($row) => $row->cases->pluck('tripPhase.project_id')
                ->push($row->targetCase?->tripPhase?->project_id))->filter()->unique())
            ->get()->keyBy(fn ($r) => $r->project_id.':'.$r->team_id);
        $ids = $page->getCollection()->flatMap(fn ($row) => $row->cases->pluck('id')->push($row->target_case_id))
            ->merge($reviews->flatMap(fn ($r) => $r->source['case_ids'] ?? []))->filter()->unique();
        $visible = BillingCase::query()->whereIn('id', $ids)
            ->whereIn('trip_phase_id', app(ScopeService::class)->tripPhases($request->user())->select('trip_phases.id'))
            ->pluck('id')->flip();
        $valid = $import->rows()->whereNull('applied_at')->whereJsonLength('errors', 0)->count();

        return [
            'id' => $import->id, 'sheet' => $import->sheet, 'status' => $import->status,
            'sha256' => $import->sha256, 'mapping_version' => $import->mapping_version,
            'expires_at' => $import->expires_at?->toISOString(),
            'summary' => ['valid' => $valid, 'invalid' => $import->rows()->whereNull('applied_at')->whereJsonLength('errors', '>', 0)->count(),
                'applied' => $import->rows()->whereNotNull('applied_at')->count(), 'total' => $import->rows()->count()],
            'next_page_url' => $page->nextPageUrl(), 'prev_page_url' => $page->previousPageUrl(),
            'rows' => $page->getCollection()->map(function ($row) use ($request, $import, $visible, $reviews): array {
                $members = $row->section === 'control_group' ? $row->cases : collect([$row->targetCase])->filter();
                $permitted = $members->isNotEmpty() && $members->every(fn ($case) => $visible->has($case->id));
                $phase = $members->first()?->tripPhase;
                $review = $phase ? $reviews->get($phase->project_id.':'.$phase->team_id) : null;
                if ($review && ! collect($review->source['case_ids'] ?? [])->every(fn ($id) => $visible->has($id))) {
                    $permitted = false;
                }
                $values = $row->normalized;
                $accountOnly = $import->sheet === '2025' && ($values['V'] ?? null) !== null
                    && (($values['M'] ?? null) === null || Decimal::compare((string) $values['M'], '0') === 0);
                $permission = $row->section === 'control_group' || $accountOnly ? 'accounting.manage' : 'payments.manage';

                return [
                    'id' => $row->id, 'section' => $row->section, 'source_row' => $row->source_row,
                    'row' => $row->source_row, 'code' => implode(' & ', $values['codes'] ?? []), 'accounting_status' => 'pending',
                    'total_dr' => $values['Q'] ?? $values['J'] ?? null, 'total_wodr' => $values['R'] ?? $values['K'] ?? null,
                    'can_apply' => $row->errors === [] && $permitted && $import->expires_at?->isFuture() && $request->user()->hasPermission($permission),
                    'group_project_id' => $phase?->project_id, 'review_version' => $review?->version,
                    'resolutions' => $row->resolutions,
                    'raw' => $row->raw, 'normalized' => $row->normalized, 'errors' => $row->errors,
                    'target_case_id' => $row->target_case_id, 'expected_version' => $row->expected_version,
                    'applied_at' => $row->applied_at?->toISOString(),
                ];
            })->values()->all(),
        ];
    }

    public function apply(Request $request, ControlImportService $service): RedirectResponse
    {
        $data = $request->validate([
            'import_id' => ['required', 'integer', 'exists:financial_imports,id'],
            'row_ids' => ['required', 'array', 'min:1', 'max:100'],
            'row_ids.*' => ['required', 'integer', 'distinct'],
            'resolutions' => ['nullable', 'array'],
            'resolutions.*' => ['array:reference,method,stage,paid_on,fiea_reference,fiea_stage,fiea_method,fiea_paid_on,project_id,review_version,lifecycle_status,note'],
            'resolutions.*.reference' => ['nullable', 'string', 'max:255'],
            'resolutions.*.method' => ['nullable', 'in:CHECK,ACH,CREDIT_CARD,PAYPAL,TRANSFER,OTHER'],
            'resolutions.*.stage' => ['nullable', 'in:initial,final'],
            'resolutions.*.paid_on' => ['nullable', 'date_format:Y-m-d'],
            'resolutions.*.fiea_reference' => ['nullable', 'string', 'max:255'],
            'resolutions.*.fiea_stage' => ['nullable', 'in:initial,final'],
            'resolutions.*.fiea_method' => ['nullable', 'in:CHECK,ACH,CREDIT_CARD,PAYPAL,TRANSFER,OTHER'],
            'resolutions.*.fiea_paid_on' => ['nullable', 'date_format:Y-m-d'],
            'resolutions.*.project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'resolutions.*.review_version' => ['nullable', 'integer', 'min:1'],
            'resolutions.*.lifecycle_status' => ['nullable', 'in:ABIERTO,POR GASTAR,FINAL PEND,CERRADO,ANULADO'],
            'resolutions.*.note' => ['nullable', 'string', 'max:2000'],
        ]);
        $import = FinancialImport::query()->where('actor_id', $request->user()->id)->findOrFail($data['import_id']);
        foreach ($data['resolutions'] ?? [] as $rowId => $resolution) {
            abort_unless(in_array((int) $rowId, array_map('intval', $data['row_ids']), true), 422);
            $row = $import->rows()->findOrFail((int) $rowId);
            abort_if($row->applied_at !== null, 409);
            $row->forceFill(['resolutions' => $resolution])->save();
        }
        $service->apply($request->user(), (int) $data['import_id'], array_map('intval', $data['row_ids']));

        return back()->with('success', 'Filas aplicadas correctamente.');
    }
}
