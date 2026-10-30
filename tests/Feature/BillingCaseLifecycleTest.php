<?php
declare(strict_types=1);
namespace Tests\Feature;
use App\Models\BillingCase;
use App\Models\Role;
use App\Models\User;
use App\Services\Finance\BillingCaseLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
final class BillingCaseLifecycleTest extends TestCase
{
    use RefreshDatabase;
    public function test_transiciones_rechazan_cierre_sin_final_y_reapertura_ordinaria(): void
    {
        $this->seed(); $now=now();
        $chapter=DB::table('chapters')->insertGetId(['chapter_type_id'=>DB::table('chapter_types')->value('id'),
            'name'=>'Capítulo estados','created_at'=>$now,'updated_at'=>$now]);
        $team=DB::table('teams')->insertGetId(['chapter_id'=>$chapter,'name'=>'Equipo estados',
            'credit_balance'=>'0.00','created_at'=>$now,'updated_at'=>$now]);
        $project=DB::table('projects')->insertGetId(['country_id'=>DB::table('countries')->value('id'),
            'code'=>'ST-01','name'=>'Proyecto estados','created_at'=>$now,'updated_at'=>$now]);
        $phase=DB::table('trip_phases')->insertGetId(['project_id'=>$project,'team_id'=>$team,
            'phase'=>'Closeout','starts_on'=>'2026-10-20','ends_on'=>'2026-10-23',
            'volunteer_count'=>1,'staff_count'=>1,'status'=>'completed',
            'created_at'=>$now,'updated_at'=>$now]);
        $actor=User::query()->create(['name'=>'Gestor estados','username'=>'gestor-estados',
            'password'=>'ClaveTemporal123!','is_active'=>true,'must_change_password'=>false]);
        $actor->forceFill(['scope_all'=>true])->save();
        $actor->roles()->attach(Role::query()->where('code','superadmin')->value('id'));
        $case=BillingCase::query()->create(['trip_phase_id'=>$phase,'invoice_type'=>'IC',
            'billing_mode'=>'final_only','metadata'=>[]]);
        $service=app(BillingCaseLifecycleService::class);
        $service->transition($actor,$case->id,'FINAL PEND',1,'Viaje concluido y revisado');
        self::assertSame('FINAL PEND',$case->fresh()->lifecycle_status);
        try {
            $service->transition($actor,$case->id,'CERRADO',2,'Cierre sin documento final');
            self::fail('El cierre requiere Final aprobado.');
        } catch(HttpException $exception) { self::assertSame(422,$exception->getStatusCode()); }
        $service->transition($actor,$case->id,'ANULADO',2,'Anulación por cancelación documentada');
        self::assertSame('ANULADO',$case->fresh()->lifecycle_status);
        try {
            $service->transition($actor,$case->id,'FINAL PEND',3,'Intento de reapertura ordinaria');
            self::fail('No hay reapertura ordinaria.');
        } catch(HttpException $exception) { self::assertSame(409,$exception->getStatusCode()); }
    }
}
