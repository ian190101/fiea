<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\Http\Requests\ApplyCreditRequest;
use App\Models\BillingCase;
use App\Models\CreditAccount;
use App\Models\CreditEntry;
use App\Models\TripPhase;
use App\Models\User;
use App\Services\Finance\CreditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
final class CreditController extends Controller
{
    public function store(ApplyCreditRequest $request,BillingCase $billingCase,CreditService $service): RedirectResponse
    {
        $d=$request->validated();
        DB::transaction(function () use ($request,$billingCase,$service,$d): void {
            User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $phase=TripPhase::query()->lockForUpdate()->findOrFail($billingCase->trip_phase_id);
            $case=BillingCase::query()->lockForUpdate()->findOrFail($billingCase->id);
            $replay=CreditEntry::query()->where('idempotency_key',$d['idempotency_key'])->exists();
            abort_unless($replay || (int)$case->version===(int)$d['version'],409);
            $account=CreditAccount::query()->where('team_id',$phase->team_id)
                ->where('currency','USD')->firstOrFail();
            $service->apply($request->user(),(int)$account->id,(int)$case->id,$d['amount'],$d['idempotency_key']);
        },3);
        return back()->with('success','Crédito aplicado correctamente.');
    }
}
