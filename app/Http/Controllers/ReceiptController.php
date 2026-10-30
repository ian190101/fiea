<?php

namespace App\Http\Controllers;

use App\Actions\Receipts\UpdateReceipt;
use App\Http\Requests\StoreReceiptRequest;
use App\Http\Requests\UpdateReceiptRequest;
use App\Models\ActualExpense;
use App\Models\BudgetLine;
use App\Models\Receipt;
use App\Models\StorageFile;
use App\Services\Receipts\ReceiptTarget;
use App\Services\Receipts\StoreReceipt;
use App\Services\Security\ScopeService;
use App\Support\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReceiptController extends Controller
{
    public function index(ScopeService $scope): Response
    {
        $actor = request()->user();
        request()->validate(['actual_expense_id' => ['nullable', 'integer', 'min:1']]);

        return Inertia::render('Receipts/Index', [
            'budgetLines' => Inertia::defer(fn () => BudgetLine::query()
                ->whereHas('billingCase', fn ($q) => $q->whereIn('trip_phase_id', $scope->tripPhases($actor)->select('trip_phases.id')))
                ->whereHas('versions', fn ($q) => $q->where('stage', 'final')->where('is_captured', true))
                ->select(['id', 'billing_case_id', 'description_en', 'description_es'])
                ->with(['billingCase:id,trip_phase_id', 'billingCase.tripPhase:id,project_id,version', 'billingCase.tripPhase.project:id,code,name'])
                ->orderBy('id')->limit(25)->get(), 'receipts'),
            'summary' => Inertia::defer(function () use ($actor): array {
                $amount = '0';
                app(ReceiptTarget::class)->visible($actor)
                    ->when(request()->integer('actual_expense_id'), fn ($q, $id) => $q->where('actual_expense_id', $id))
                    ->select(['id', 'amount'])->chunkById(500, function ($receipts) use (&$amount): void {
                        foreach ($receipts as $receipt) {
                            $amount = Decimal::add($amount, $receipt->amount);
                        }
                    });

                return ['actual_expense_id' => request()->integer('actual_expense_id') ?: null, 'amount' => Decimal::money($amount)];
            }, 'receipts'),
            'actualExpenses' => Inertia::defer(fn () => $scope->constrain(ActualExpense::query(), $actor, 'actual_expenses.trip_phase_id')
                ->with(['tripPhase:id,project_id,version', 'tripPhase.project:id,code,name', 'expenseCategory:id,name,fund_type'])
                ->orderByDesc('reported_at')
                ->limit(25)->get(['id', 'trip_phase_id', 'expense_category_id', 'description', 'real_total', 'receipt_number', 'fund_type']), 'receipts'),
            'receipts' => Inertia::defer(fn () => app(ReceiptTarget::class)->visible($actor)
                ->when(request()->integer('actual_expense_id'), fn ($q, $id) => $q->where('actual_expense_id', $id))
                ->with([
                    'budgetLine:id,billing_case_id,description_en,description_es',
                    'budgetLine.billingCase:id,trip_phase_id',
                    'budgetLine.billingCase.tripPhase:id,project_id,version',
                    'budgetLine.billingCase.tripPhase.project:id,code,name',
                    'actualExpense:id,trip_phase_id,description,real_total',
                    'actualExpense.tripPhase:id,project_id,version',
                    'actualExpense.tripPhase.project:id,code,name',
                    'storageFile:id,original_name,mime_type,size_bytes',
                ])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->cursorPaginate(25, ['id', 'actual_expense_id', 'budget_line_id', 'storage_file_id', 'receipt_number', 'issued_on', 'amount', 'created_at']), 'receipts'),
        ]);

    }

    public function store(StoreReceiptRequest $request, StoreReceipt $action): RedirectResponse
    {
        $action->execute($request->user(), $request->safe()->except('file'), $request->file('file'));

        return back()->with('success', 'Comprobante subido correctamente.');
    }

    public function update(UpdateReceiptRequest $request, Receipt $receipt, UpdateReceipt $action): RedirectResponse
    {
        $action->execute($request->user(), $receipt, $request->validated());

        return back()->with('success', 'Comprobante actualizado correctamente.');
    }

    public function destroy(UpdateReceiptRequest $request, Receipt $receipt,
        UpdateReceipt $action): RedirectResponse
    {
        $action->delete($request->user(), $receipt, $request->validated());

        return back()->with('success', 'Comprobante eliminado correctamente.');
    }

    public function show(Receipt $receipt): StreamedResponse
    {
        $this->authorizeReceipt($receipt, request(), 'receipts.view');
        $receipt->load('storageFile');
        abort_unless($receipt->storageFile instanceof StorageFile, 404);

        $disk = $receipt->storageFile->provider === 'cloudflare_r2' ? 'r2' : 'local';

        $name = str_replace(["\r", "\n", '\\', '/'], '_', $receipt->storageFile->original_name);

        return Storage::disk($disk)->download(
            $receipt->storageFile->object_key,
            mb_substr($name, 0, 160),
            ['Content-Type' => $receipt->storageFile->mime_type,
                'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']
        );
    }

    private function authorizeReceipt(Receipt $receipt, Request $request, string $permission): void
    {
        $targets = app(ReceiptTarget::class);
        $targets->visible($request->user())->findOrFail($receipt->id);
        app(ScopeService::class)->authorize($request->user(), $permission,
            $targets->phaseId($targets->resolve($receipt->only(['actual_expense_id', 'budget_line_id']))));
    }
}
