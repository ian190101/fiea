<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Queries\AuditLogQuery;
use App\Queries\DashboardQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ScopedQueryPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_auditoria_pagina_25_y_dashboard_omite_finanzas_sin_permiso(): void
    {
        $actor=User::factory()->create(['is_active'=>true]);
        $actor->forceFill(['scope_all'=>true])->save();
        $permission=Permission::query()->create([
            'code'=>'audit_logs.view','name'=>'Ver auditoría','module'=>'audit_logs']);
        $role=Role::query()->create(['code'=>'auditor_prueba','name'=>'Auditor prueba']);
        $role->permissions()->attach($permission);
        $actor->roles()->attach($role);
        for ($i=0;$i<100;$i++) AuditLog::query()->create([
            'user_id'=>$actor->id,'action'=>'test_event','module'=>'tests',
            'auditable_type'=>User::class,'auditable_id'=>$actor->id,
            'metadata'=>['index'=>$i],
        ]);
        DB::enableQueryLog();
        $result=app(AuditLogQuery::class)->for($actor,[]);
        $queries=count(DB::getQueryLog());
        DB::disableQueryLog();
        self::assertCount(25,$result['auditLogs']->items());
        self::assertLessThanOrEqual(15,$queries);
        $dashboard=app(DashboardQuery::class)->for($actor);
        self::assertArrayNotHasKey('financials',$dashboard);
        self::assertArrayNotHasKey('accountingStatus',$dashboard);
    }
}
