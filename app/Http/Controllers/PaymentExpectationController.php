<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\Http\Requests\UpdatePaymentExpectationRequest;
use App\Models\BillingCase;
use App\Services\Finance\TreasuryService;
use Illuminate\Http\RedirectResponse;
final class PaymentExpectationController extends Controller
{
    public function update(UpdatePaymentExpectationRequest $request,BillingCase $billingCase,string $leg,TreasuryService $service): RedirectResponse
    {
        $service->setExpectation($request->user(),(int)$billingCase->id,$leg,$request->validated());
        return back()->with('success','Expectativa actualizada correctamente.');
    }
}
