<?php
declare(strict_types=1);
namespace App\Http\Controllers;

use App\Http\Requests\AlertFilterRequest;
use App\Queries\AlertQuery;
use Inertia\Inertia;
use Inertia\Response;

final class AlertController extends Controller
{
    public function index(AlertFilterRequest $request, AlertQuery $query): Response
    {
        $filters=[
            'type'=>$request->validated('type'),
            'severity'=>$request->validated('severity'),
            'q'=>$request->validated('q'),
        ];
        $data=$query->for($request->user(),$filters);
        return Inertia::render('Alerts/Index',[
            'filters'=>$filters,'alerts'=>$data['alerts'],'summary'=>$data['summary'],
            'types'=>[
                ['value'=>'invoice','label'=>'Invoices'],
                ['value'=>'accounting','label'=>'Contabilidad'],
                ['value'=>'receipt','label'=>'Recibos'],
                ['value'=>'email','label'=>'Correos'],
                ['value'=>'trip','label'=>'Viajes'],
            ],
            'severities'=>[
                ['value'=>'critical','label'=>'Crítica'],
                ['value'=>'warning','label'=>'Advertencia'],
                ['value'=>'info','label'=>'Informativa'],
            ],
        ]);
    }
}
