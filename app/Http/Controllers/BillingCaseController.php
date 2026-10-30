<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreBillingCaseRequest;
use App\Http\Requests\UpdateBillingCaseSettingsRequest;
use App\Models\BillingCase;
use App\Models\TripPhase;
use App\Services\Finance\BillingCaseService;
use Illuminate\Http\RedirectResponse;

final class BillingCaseController extends Controller
{
    public function store(StoreBillingCaseRequest $request, TripPhase $tripPhase, BillingCaseService $service): RedirectResponse
    {
        $d = $request->validated();
        $d['metadata'] = $d['metadata'] ?? [];
        $service->create($request->user(), (int) $tripPhase->id, $d);

        return redirect()->route('budgets.show', [$tripPhase, 'invoice_type' => $d['invoice_type'],
            'stage' => $d['billing_mode'] === 'final_only' ? 'final' : 'initial',
            'view' => strtolower($d['invoice_type']).($d['billing_mode'] === 'final_only' ? '_final' : '_initial')])
            ->with('success', 'Expediente creado correctamente.');
    }

    public function update(UpdateBillingCaseSettingsRequest $request, BillingCase $billingCase, BillingCaseService $service): RedirectResponse
    {
        $d = $request->validated();
        $d['bank_fee_initial'] = $d['bank_fee_initial'] ?? $billingCase->bank_fee_initial;
        $d['bank_fee_final'] = $d['bank_fee_final'] ?? $billingCase->bank_fee_final;
        $d['final_contingency'] = array_key_exists('final_contingency', $d)
            ? $d['final_contingency'] : $billingCase->final_contingency;
        $d['reason'] = $d['reason'] ?? '';
        $service->updateSettings($request->user(), (int) $billingCase->id, (int) $d['version'], $d);

        return back()->with('success', 'Configuración actualizada correctamente.');
    }
}
