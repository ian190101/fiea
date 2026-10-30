<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Data\Financial\RecordPaymentData;
use App\Models\BillingCase;
use App\Models\CreditAccount;
use App\Models\CreditEntry;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use App\Services\Finance\CreditService;
use App\Services\Finance\TreasuryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class CreditConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_excedente_real_se_libera_una_vez_y_no_se_sobregira(): void
    {
        [$actor,$source,$destination,$sourceInvoice,$teamId]=$this->scenario();
        app(TreasuryService::class)->record($actor,new RecordPaymentData(
            $source->id,$sourceInvoice->id,'chapter_to_eia','final','receipt',
            '633.00','2026-10-20','ACH','ACH-633',null,
            '123e4567-e89b-12d3-a456-426614174060',1));
        $source->refresh()->forceFill(['lifecycle_status'=>'CERRADO'])->save();
        $credit=app(CreditService::class);
        $earned=$credit->earn($actor,$source->id,'123e4567-e89b-12d3-a456-426614174061');
        self::assertSame('50.00',$earned->amount);
        self::assertSame($earned->id,$credit->earn($actor,$source->id,
            '123e4567-e89b-12d3-a456-426614174061')->id);
        $account=CreditAccount::query()->where('team_id',$teamId)->firstOrFail();
        self::assertSame('50.00',$account->balance);
        app(TreasuryService::class)->record($actor,new RecordPaymentData(
            $source->id,$sourceInvoice->id,'chapter_to_eia','final','refund',
            '20.00','2026-10-21','ACH','ACH-633','Devolución de excedente documentada',
            '123e4567-e89b-12d3-a456-426614174064',3));
        self::assertSame('30.00',$account->fresh()->balance);
        app(TreasuryService::class)->record($actor,new RecordPaymentData(
            $source->id,$sourceInvoice->id,'chapter_to_eia','final','receipt',
            '20.00','2026-10-22','ACH','ACH-20','Nuevo cobro documentado y revisado',
            '123e4567-e89b-12d3-a456-426614174065',4));
        self::assertSame('50.00',$account->fresh()->balance);
        $applied=$credit->apply($actor,$account->id,$destination->id,'40.00',
            '123e4567-e89b-12d3-a456-426614174062');
        self::assertSame('-40.00',$applied->amount);
        self::assertSame('10.00',$account->fresh()->balance);
        try {
            $credit->apply($actor,$account->id,$destination->id,'40.00',
                '123e4567-e89b-12d3-a456-426614174063');
            self::fail('No debe sobregirar el crédito.');
        } catch (HttpException $exception) {
            self::assertSame(409,$exception->getStatusCode());
        }
        self::assertSame(4,CreditEntry::query()->count());
        self::assertSame('583.00',$sourceInvoice->fresh()->grand_total);
    }

    private function scenario(): array
    {
        $this->seed(); $now=now();
        $chapter=DB::table('chapters')->insertGetId([
            'chapter_type_id'=>DB::table('chapter_types')->value('id'),'name'=>'Capítulo crédito',
            'created_at'=>$now,'updated_at'=>$now,
        ]);
        $team=DB::table('teams')->insertGetId(['chapter_id'=>$chapter,'name'=>'Equipo crédito',
            'credit_balance'=>'0.00','created_at'=>$now,'updated_at'=>$now]);
        $project=DB::table('projects')->insertGetId([
            'country_id'=>DB::table('countries')->value('id'),'code'=>'CR-01',
            'name'=>'Proyecto crédito','created_at'=>$now,'updated_at'=>$now,
        ]);
        $phase=DB::table('trip_phases')->insertGetId([
            'project_id'=>$project,'team_id'=>$team,'phase'=>'Initial Visit',
            'starts_on'=>'2026-10-20','ends_on'=>'2026-10-23','volunteer_count'=>1,
            'staff_count'=>1,'status'=>'completed','created_at'=>$now,'updated_at'=>$now,
        ]);
        $actor=User::query()->create(['name'=>'Tesorero crédito','username'=>'credito',
            'password'=>'ClaveTemporal123!','is_active'=>true,'must_change_password'=>false]);
        $actor->forceFill(['scope_all'=>true])->save();
        $actor->roles()->attach(Role::query()->where('code','superadmin')->value('id'));
        $source=BillingCase::query()->create(['trip_phase_id'=>$phase,'invoice_type'=>'IC',
            'billing_mode'=>'standard','metadata'=>[]]);
        $destination=BillingCase::query()->create(['trip_phase_id'=>$phase,'invoice_type'=>'MAT',
            'billing_mode'=>'standard','metadata'=>[]]);
        $sourceInvoice=Invoice::query()->create(['trip_phase_id'=>$phase,'code'=>'CR-FINAL',
            'type'=>'IC','stage'=>'final','status'=>'approved','total_dr'=>'500.00',
            'total_wodr'=>'83.00','grand_total'=>'583.00','balance_conciliation'=>'-50.00']);
        $sourceInvoice->forceFill(['billing_case_id'=>$source->id])->save();
        $source->forceFill(['current_final_id'=>$sourceInvoice->id])->save();
        $destinationInvoice=Invoice::query()->create(['trip_phase_id'=>$phase,'code'=>'CR-DEST',
            'type'=>'MAT','stage'=>'initial','status'=>'approved','total_dr'=>'100.00',
            'total_wodr'=>'0.00','grand_total'=>'100.00','balance_conciliation'=>'100.00']);
        $destinationInvoice->forceFill(['billing_case_id'=>$destination->id])->save();
        $destination->forceFill(['current_initial_id'=>$destinationInvoice->id])->save();
        return [$actor,$source,$destination,$sourceInvoice,$team];
    }
}
