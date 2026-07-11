<?php

namespace App\Http\Controllers;

use App\Models\ContactPerson;
use App\Models\Invoice;
use App\Models\SystemSetting;
use App\Models\TripPhase;
use App\Services\SystemNotificationService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Invoices/Index', [
            'invoices' => Inertia::defer(fn () => Invoice::query()
                ->with([
                    'tripPhase:id,project_id,team_id,phase,starts_on,ends_on',
                    'tripPhase.project:id,code,name',
                    'tripPhase.team:id,name,credit_balance',
                    'contactPerson:id,full_name,email',
                    'createdBy:id,name,username',
                    'approvedBy:id,name,username',
                    'pdfFile:id,original_name',
                ])
                ->orderByDesc('created_at')
                ->get(), 'invoices'),
            'tripPhases' => Inertia::defer(fn () => $this->tripPhases(), 'invoices'),
            'contacts' => Inertia::defer(fn () => ContactPerson::query()
                ->orderBy('full_name')
                ->get(['id', 'full_name', 'email']), 'invoices'),
            'phaseTotals' => Inertia::defer(fn () => $this->phaseTotals($this->tripPhases()->pluck('id')->all()), 'invoices'),
            'settings' => [
                'lockFinalInvoiceByDefault' => SystemSetting::query()->first()?->lock_final_invoice_by_default ?? true,
            ],
        ]);
    }

    private function tripPhases()
    {
        return TripPhase::query()
            ->with(['project:id,code,name', 'team:id,name,credit_balance'])
            ->orderByDesc('starts_on')
            ->get(['id', 'project_id', 'team_id', 'phase', 'starts_on', 'ends_on', 'status']);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'trip_phase_id' => ['required', 'integer', Rule::exists('trip_phases', 'id')],
            'contact_person_id' => ['nullable', 'integer', Rule::exists('contact_people', 'id')],
            'type' => ['required', 'string', Rule::in(['IC', 'MAT'])],
            'stage' => ['required', 'string', Rule::in(['initial', 'final'])],
        ]);

        $tripPhase = TripPhase::query()
            ->with(['project:id,code', 'team:id,credit_balance'])
            ->findOrFail($data['trip_phase_id']);

        $totals = $this->invoiceTotals($tripPhase);
        $settings = SystemSetting::query()->firstOrCreate(['id' => 1]);
        $shouldLock = $data['stage'] === 'final' && $settings->lock_final_invoice_by_default;

        Invoice::query()->create([
            ...$data,
            'created_by_id' => $request->user()?->id,
            'code' => $this->makeInvoiceCode($tripPhase, $data['type'], $data['stage']),
            'status' => 'draft',
            'total_dr' => $totals['total_dr'],
            'total_wodr' => $totals['total_wodr'],
            'grand_total' => $totals['grand_total'],
            'balance_conciliation' => $totals['balance_conciliation'],
            'locked_at' => $shouldLock ? now() : null,
        ]);

        return back()->with('success', 'Invoice creado correctamente.');
    }

    public function update(Request $request, Invoice $invoice): RedirectResponse
    {
        if ($invoice->locked_at) {
            return back()->withErrors(['invoice' => 'Este invoice esta bloqueado y no puede modificarse.']);
        }

        $data = $request->validate([
            'contact_person_id' => ['nullable', 'integer', Rule::exists('contact_people', 'id')],
            'status' => ['required', 'string', Rule::in(['draft', 'approved', 'sent', 'paid', 'void'])],
        ]);

        $invoice->fill([
            'contact_person_id' => $data['contact_person_id'] ?? null,
            'status' => $data['status'],
            'sent_at' => $data['status'] === 'sent' ? ($invoice->sent_at ?? now()) : $invoice->sent_at,
            'paid_at' => $data['status'] === 'paid' ? ($invoice->paid_at ?? now()) : $invoice->paid_at,
        ])->save();

        return back()->with('success', 'Invoice actualizado correctamente.');
    }

    public function approve(Request $request, Invoice $invoice, SystemNotificationService $notifications): RedirectResponse
    {
        if ($invoice->locked_at && $invoice->status !== 'draft') {
            return back()->withErrors(['invoice' => 'Este invoice ya esta bloqueado.']);
        }

        $invoice->fill([
            'status' => 'approved',
            'approved_by_id' => $request->user()?->id,
            'locked_at' => $invoice->stage === 'final' ? ($invoice->locked_at ?? now()) : $invoice->locked_at,
        ])->save();

        $notifications->notifyPermission(
            permission: 'invoice_emails.manage',
            type: 'invoice_approved',
            severity: 'info',
            title: 'Invoice aprobada',
            body: "La invoice {$invoice->code} ya puede prepararse para envio.",
            actionUrl: route('invoice-emails.index'),
            actor: $request->user(),
            data: ['invoice_id' => $invoice->id],
        );

        return back()->with('success', 'Invoice aprobado correctamente.');
    }

    public function destroy(Invoice $invoice): RedirectResponse
    {
        if ($invoice->locked_at) {
            return back()->withErrors(['invoice' => 'Este invoice esta bloqueado y no puede eliminarse.']);
        }

        try {
            $invoice->delete();
        } catch (QueryException) {
            return back()->withErrors([
                'invoice' => 'No se puede eliminar porque tiene documentos o correos relacionados.',
            ]);
        }

        return back()->with('success', 'Invoice eliminado correctamente.');
    }

    /**
     * @return array<string, array{total_dr: float, total_wodr: float, grand_total: float, team_credit: float, balance_conciliation: float}>
     */
    private function phaseTotals(array $phaseIds): array
    {
        if ($phaseIds === []) {
            return [];
        }

        $expenseTotals = DB::table('actual_expenses')
            ->select('trip_phase_id')
            ->selectRaw("SUM(CASE WHEN fund_type = 'DR' THEN real_total ELSE 0 END) as total_dr")
            ->selectRaw("SUM(CASE WHEN fund_type = 'WODR' THEN real_total ELSE 0 END) as total_wodr")
            ->whereIn('trip_phase_id', $phaseIds)
            ->groupBy('trip_phase_id')
            ->get()
            ->keyBy('trip_phase_id');

        $teams = TripPhase::query()
            ->with('team:id,credit_balance')
            ->whereIn('id', $phaseIds)
            ->get(['id', 'team_id'])
            ->keyBy('id');

        return collect($phaseIds)
            ->mapWithKeys(function ($phaseId) use ($expenseTotals, $teams) {
                $row = $expenseTotals->get($phaseId);
                $totalDr = (float) ($row->total_dr ?? 0);
                $totalWodr = (float) ($row->total_wodr ?? 0);
                $grandTotal = round($totalDr + $totalWodr, 2);
                $teamCredit = (float) ($teams->get($phaseId)?->team?->credit_balance ?? 0);

                return [(string) $phaseId => [
                    'total_dr' => round($totalDr, 2),
                    'total_wodr' => round($totalWodr, 2),
                    'grand_total' => $grandTotal,
                    'team_credit' => round($teamCredit, 2),
                    'balance_conciliation' => round(max($grandTotal - $teamCredit, 0), 2),
                ]];
            })
            ->all();
    }

    /**
     * Los totales se calculan aqui para que el navegador nunca pueda manipular montos de invoice.
     *
     * @return array{total_dr: float, total_wodr: float, grand_total: float, balance_conciliation: float}
     */
    private function invoiceTotals(TripPhase $tripPhase): array
    {
        $totals = $this->phaseTotals([$tripPhase->id])[(string) $tripPhase->id];

        return [
            'total_dr' => $totals['total_dr'],
            'total_wodr' => $totals['total_wodr'],
            'grand_total' => $totals['grand_total'],
            'balance_conciliation' => $totals['balance_conciliation'],
        ];
    }

    private function makeInvoiceCode(TripPhase $tripPhase, string $type, string $stage): string
    {
        $stageCode = $stage === 'final' ? 'FINAL' : 'INITIAL';
        $baseCode = sprintf('%s-%s-%s-%s', $tripPhase->project->code, $tripPhase->phase, $type, $stageCode);
        $normalized = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '-', $baseCode));
        $normalized = trim($normalized, '-');

        $count = Invoice::query()
            ->where('trip_phase_id', $tripPhase->id)
            ->where('type', $type)
            ->where('stage', $stage)
            ->count();

        return $count === 0 ? $normalized : sprintf('%s-%02d', $normalized, $count + 1);
    }
}
