<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\Data\Financial\RecordPaymentData;
use App\Http\Requests\RecordPaymentRequest;
use App\Models\BillingCase;
use App\Services\Finance\TreasuryService;
use Illuminate\Http\RedirectResponse;
final class TreasuryPaymentController extends Controller
{
    public function store(RecordPaymentRequest $request, BillingCase $billingCase, TreasuryService $service): RedirectResponse
    {
        $d=$request->validated();
        $service->record($request->user(),new RecordPaymentData(
            (int)$billingCase->id,isset($d['invoice_id'])?(int)$d['invoice_id']:null,
            $d['leg'],$d['stage'],$d['direction'],$d['amount'],$d['paid_on'],
            $d['method'],$d['reference']??null,$d['note']??null,$d['idempotency_key'],(int)$d['version']
        ));
        return back()->with('success','Pago registrado correctamente.');
    }
}
