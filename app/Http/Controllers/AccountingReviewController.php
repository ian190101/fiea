<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\Http\Requests\UpdateAccountingReviewRequest;
use App\Models\AccountingReview;
use App\Services\Finance\AccountingReviewService;
use Illuminate\Http\RedirectResponse;
final class AccountingReviewController extends Controller
{
    public function update(UpdateAccountingReviewRequest $request,AccountingReview $accountingReview,AccountingReviewService $service): RedirectResponse
    {
        $d=$request->validated();
        $service->update($request->user(),(int)$accountingReview->id,(int)$d['version'],$d['accounting_total']??null,$d['note']??'');
        return back()->with('success','Revisión registrada correctamente.');
    }
}
