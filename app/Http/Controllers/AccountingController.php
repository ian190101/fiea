<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\User;
use App\Queries\AccountingQuery;
use App\Services\Security\ScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AccountingController extends Controller
{
    public function index(Request $request): Response
    {
        $actor = $request->user();
        $status = $request->validate(['status' => ['nullable', Rule::in(['pending', 'reconciled', 'flagged'])]])['status'] ?? null;

        return Inertia::render('Accounting/Index', [
            'invoices' => Inertia::defer(fn () => $this->invoices($status, $actor), 'accounting'),
            'summary' => Inertia::defer(fn () => $this->summary($status, $actor), 'accounting'),
            'filters' => [
                'status' => $status,
            ],
            'importPreview' => Inertia::defer(function () use ($request) {
                $id = $request->session()->get('financial_import_id');

                return $id ? app(AccountingImportController::class)->buildPreview($request, (int) $id) : null;
            }, 'accounting'),
        ]);
    }

    public function update(Request $request, Invoice $invoice): RedirectResponse
    {
        app(ScopeService::class)->authorize($request->user(), 'accounting.manage', (int) $invoice->trip_phase_id);
        $request->validate([
            'total_dr' => ['prohibited'], 'total_wodr' => ['prohibited'],
            'grand_total' => ['prohibited'], 'balance_conciliation' => ['prohibited'],
        ]);
        abort(410, 'La revisión contable se registra en accounting_reviews.');
    }

    /**
     * @return array<string, int|string|bool|null>
     */
    private function summary(?string $status, User $actor): array
    {
        return app(AccountingQuery::class)->summary($actor, $status);
    }

    private function invoices(?string $status, User $actor)
    {
        return app(AccountingQuery::class)->invoices($actor, $status);
    }
}
