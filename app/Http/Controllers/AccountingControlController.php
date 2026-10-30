<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\Services\Finance\ControlQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
final class AccountingControlController extends Controller
{
    public function index(Request $request, ControlQuery $query): Response
    {
        $d=$request->validate(['year'=>['nullable','integer','between:2000,2100'],
            'cursor'=>['nullable','string','max:500'],'basis'=>['nullable','in:case_lifetime,period_cash']]);
        $year=(int)($d['year']??date('Y'));
        $control=$query->forYearBasis($request->user(),$year,
            $d['cursor']??null,$d['basis']??'case_lifetime');
        return Inertia::render('Accounting/Control',array_merge($control,[
            'years'=>range((int)date('Y'),2025),
            'filters'=>['year'=>$year,'cursor'=>$d['cursor']??null,'basis'=>$control['basis']],
        ]));
    }
}
