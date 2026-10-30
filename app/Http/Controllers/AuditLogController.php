<?php
declare(strict_types=1);
namespace App\Http\Controllers;

use App\Http\Requests\AuditLogFilterRequest;
use App\Queries\AuditLogQuery;
use Inertia\Inertia;
use Inertia\Response;

final class AuditLogController extends Controller
{
    public function index(AuditLogFilterRequest $request, AuditLogQuery $query): Response
    {
        $filters=$request->validated();
        return Inertia::render('AuditLogs/Index',$query->for($request->user(),$filters)+[
            'filters'=>[
                'module'=>$filters['module']??'',
                'action'=>$filters['action']??'',
                'user_id'=>$filters['user_id']??'',
            ],
        ]);
    }
}
