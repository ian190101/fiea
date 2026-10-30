<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\Http\Requests\StoreBudgetLineRequest;
use App\Http\Requests\DeleteBudgetLineRequest;
use App\Models\BillingCase;
use App\Models\BudgetLine;
use App\Services\Finance\BudgetService;
use Illuminate\Http\RedirectResponse;
final class BudgetLineController extends Controller
{
    public function store(StoreBudgetLineRequest $request,BillingCase $billingCase,BudgetService $service): RedirectResponse
    {
        $service->addLine($request->user(),(int)$billingCase->id,$request->validated());
        return back()->with('success','Línea agregada correctamente.');
    }
    public function destroy(DeleteBudgetLineRequest $request,BillingCase $billingCase,BudgetLine $budgetLine,BudgetService $service): RedirectResponse
    {
        $service->deleteLine($request->user(),(int)$billingCase->id,(int)$budgetLine->id,(int)$request->validated('version'));
        return back()->with('success','Línea eliminada correctamente.');
    }
}
