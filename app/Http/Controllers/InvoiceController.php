<?php

namespace App\Http\Controllers;

use App\Http\Requests\InvoiceVersionRequest;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\VoidInvoiceRequest;
use App\Models\BillingCase;
use App\Models\ContactPerson;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Finance\InvoiceLifecycleService;
use App\Services\Security\ScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    public function index(Request $request, ScopeService $scope): Response
    {
        $actor = $request->user();

        return Inertia::render('Invoices/Index', [
            'invoices' => Inertia::defer(fn () => $scope->constrain(Invoice::query(), $actor, 'invoices.trip_phase_id')
                ->with([
                    'tripPhase:id,project_id,team_id,phase,starts_on,ends_on',
                    'tripPhase.project:id,code,name',
                    'tripPhase.team:id,name,credit_balance',
                    'contactPerson:id,full_name,email',
                    'createdBy:id,name,username',
                    'approvedBy:id,name,username',
                    'pdfFile:id,original_name',
                ])
                ->orderByDesc('invoices.created_at')
                ->orderByDesc('invoices.id')
                ->cursorPaginate(25)->withQueryString(), 'invoices'),
            'tripPhases' => Inertia::defer(fn () => $this->tripPhases($actor, $scope), 'invoices'),
            'contacts' => Inertia::defer(fn () => ContactPerson::query()
                ->whereHas('assignments', fn ($q) => $q
                    ->whereIn('team_id', $scope->tripPhases($actor)->select('trip_phases.team_id'))
                    ->orWhere(fn ($c) => $c->whereNull('team_id')->whereIn('chapter_id', DB::table('teams')->select('chapter_id')
                        ->whereIn('id', $scope->tripPhases($actor)->select('trip_phases.team_id')))))
                ->orderBy('full_name')
                ->get(['id', 'full_name', 'email']), 'invoices'),
            'billingCases' => Inertia::defer(fn () => BillingCase::query()->whereIn('trip_phase_id', $scope->tripPhases($actor)->select('trip_phases.id'))->with(['currentInitial:id,code,status,grand_total', 'currentFinal:id,code,status,grand_total'])->get(['id', 'trip_phase_id', 'invoice_type', 'billing_mode', 'version', 'current_initial_id', 'current_final_id']), 'invoices'),
        ]);
    }

    private function tripPhases(User $actor, ScopeService $scope)
    {
        return $scope->tripPhases($actor)
            ->with(['project:id,code,name', 'team:id,name,credit_balance'])
            ->orderByDesc('starts_on')
            ->get(['id', 'project_id', 'team_id', 'phase', 'starts_on', 'ends_on', 'status']);
    }

    public function store(StoreInvoiceRequest $request, InvoiceLifecycleService $service): RedirectResponse
    {
        $service->create($request->user(), $request->data());

        return back()->with('success', 'Borrador creado. Revisa los importes antes de aprobar.');
    }

    public function approve(InvoiceVersionRequest $request, Invoice $invoice, InvoiceLifecycleService $service): RedirectResponse
    {
        $service->approve($request->user(), (int) $invoice->id, $request->integer('version'));

        return back()->with('success', 'Invoice aprobada y bloqueada.');
    }

    public function refresh(InvoiceVersionRequest $request, Invoice $invoice, InvoiceLifecycleService $service): RedirectResponse
    {
        $service->refreshDraft($request->user(), (int) $invoice->id, $request->integer('version'));

        return back()->with('success', 'Borrador actualizado. Revisa sus nuevos importes.');
    }

    public function void(VoidInvoiceRequest $request, Invoice $invoice, InvoiceLifecycleService $service): RedirectResponse
    {
        $service->void($request->user(), (int) $invoice->id, $request->integer('version'), $request->string('reason')->toString());

        return back()->with('success', 'Borrador anulado. Se conserva su historial.');
    }
}
