<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Data\Financial\RecordPaymentData;
use App\Models\BillingCase;
use App\Models\BillingCaseCode;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use App\Services\Finance\ControlQuery;
use App\Services\Finance\TreasuryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TreasuryTest extends TestCase
{
    use RefreshDatabase;

    public function test_pagos_parciales_y_dos_tramos_no_alteran_invoice(): void
    {
        [$actor,$case,$invoice]=$this->scenario();
        $service=app(TreasuryService::class);
        $first=new RecordPaymentData($case->id,$invoice->id,'chapter_to_eia','initial',
            'receipt','100.00','2026-10-20','CHECK','CHK-1',null,
            '123e4567-e89b-12d3-a456-426614174050',1);
        $payment=$service->record($actor,$first);
        self::assertSame($payment->id,$service->record($actor,$first)->id);
        $page=app(ControlQuery::class)->forYear($actor,2026,null);
        self::assertSame('PENDIENTE',$page['rows'][0]['eia']['status']);
        self::assertSame('PENDIENTE',$page['rows'][0]['fiea']['status']);
        $service->record($actor,new RecordPaymentData($case->id,$invoice->id,'chapter_to_eia','initial',
            'receipt','533.00','2026-10-21','ACH','ACH-2',null,
            '123e4567-e89b-12d3-a456-426614174051',2));
        $page=app(ControlQuery::class)->forYear($actor,2026,null);
        self::assertSame('PAGADO',$page['rows'][0]['eia']['status']);
        self::assertSame('PENDIENTE',$page['rows'][0]['fiea']['status']);
        self::assertSame('633.00',$page['rows'][0]['eia']['received']);
        self::assertSame('633.00',$invoice->fresh()->grand_total);
        $service->record($actor,new RecordPaymentData($case->id,$invoice->id,'chapter_to_eia','initial',
            'refund','20.00','2026-10-22','CHECK','CHK-1','Devolución documentada',
            '123e4567-e89b-12d3-a456-426614174052',3));
        $service->record($actor,new RecordPaymentData($case->id,$invoice->id,'eia_to_fiea','initial',
            'receipt','50.00','2026-10-23','ACH','FIEA-50',null,
            '123e4567-e89b-12d3-a456-426614174053',4));
        $control=app(ControlQuery::class)->forYear($actor,2026,null);
        self::assertCount(1,$control['rows']);
        self::assertSame('613.00',$control['rows'][0]['eia']['received']);
        self::assertSame('50.00',$control['rows'][0]['fiea']['received']);
        self::assertSame('613.00',$control['summary']['income']);
        self::assertSame('633.00',$invoice->fresh()->grand_total);
    }

    /** @return array{User,BillingCase,Invoice} */
    private function scenario(): array
    {
        $this->seed();
        $now=now();
        $chapter=DB::table('chapters')->insertGetId([
            'chapter_type_id'=>DB::table('chapter_types')->value('id'),
            'name'=>'Capítulo tesorería','created_at'=>$now,'updated_at'=>$now,
        ]);
        $team=DB::table('teams')->insertGetId([
            'chapter_id'=>$chapter,'name'=>'Equipo tesorería','credit_balance'=>'0.00',
            'created_at'=>$now,'updated_at'=>$now,
        ]);
        $project=DB::table('projects')->insertGetId([
            'country_id'=>DB::table('countries')->value('id'),
            'code'=>'TR-01','name'=>'Proyecto tesorería','created_at'=>$now,'updated_at'=>$now,
        ]);
        $phase=DB::table('trip_phases')->insertGetId([
            'project_id'=>$project,'team_id'=>$team,'phase'=>'Initial Visit',
            'starts_on'=>'2026-10-20','ends_on'=>'2026-10-23','volunteer_count'=>1,
            'staff_count'=>1,'status'=>'draft','created_at'=>$now,'updated_at'=>$now,
        ]);
        $actor=User::query()->create(['name'=>'Tesorero','username'=>'tesorero',
            'password'=>'ClaveTemporal123!','is_active'=>true,'must_change_password'=>false]);
        $actor->forceFill(['scope_all'=>true])->save();
        $actor->roles()->attach(Role::query()->where('code','superadmin')->value('id'));
        $case=BillingCase::query()->create([
            'trip_phase_id'=>$phase,'invoice_type'=>'IC','billing_mode'=>'standard','metadata'=>[],
        ]);
        BillingCaseCode::query()->create(['billing_case_id'=>$case->id,'code'=>'IE-26-001','year'=>2026]);
        BillingCaseCode::query()->create(['billing_case_id'=>$case->id,'code'=>'IE-26-001-MAT','year'=>2026]);
        $invoice=Invoice::query()->create([
            'trip_phase_id'=>$phase,'code'=>'IE-26-001','type'=>'IC','stage'=>'initial',
            'status'=>'approved','total_dr'=>'550.00','total_wodr'=>'83.00',
            'grand_total'=>'633.00','balance_conciliation'=>'633.00',
        ]);
        $invoice->forceFill(['billing_case_id'=>$case->id])->save();
        $case->forceFill(['current_initial_id'=>$invoice->id])->save();
        return [$actor,$case,$invoice];
    }
}
