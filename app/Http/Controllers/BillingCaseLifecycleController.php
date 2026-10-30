<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\Http\Requests\TransitionBillingCaseRequest;
use App\Models\BillingCase;
use App\Services\Finance\BillingCaseLifecycleService;
use Illuminate\Http\RedirectResponse;
final class BillingCaseLifecycleController extends Controller
{
    public function store(TransitionBillingCaseRequest $request,BillingCase $billingCase,BillingCaseLifecycleService $service): RedirectResponse
    {
        $d=$request->validated();
        $service->transition($request->user(),(int)$billingCase->id,$d['target'],(int)$d['version'],$d['reason']);
        return back()->with('success','Estado actualizado correctamente.');
    }
}
