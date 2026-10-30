<?php
declare(strict_types=1);
namespace App\Console\Commands;

use App\Models\ActualExpense;
use App\Models\EstimatedExpense;
use App\Models\Invoice;
use App\Models\BillingCase;
use App\Models\BudgetLine;
use App\Models\BudgetLineVersion;
use App\Models\CreditAccount;
use App\Models\CreditEntry;
use App\Models\TripPhase;
use App\Models\Team;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ApplyFinancialMigration extends Command
{
    protected $signature='finance:migration-apply {--manifest=storage/app/private/finance-review.json}';
    protected $description='Aplica un manifest financiero revisado';

    public function handle(): int
    {
        $path=(string)$this->option('manifest');
        $target=str_starts_with($path,'storage/app/private/')
            ? storage_path('app/private/'.substr($path,20)) : $path;
        $root=storage_path('app/private');
        $normalized=str_replace('\\','/',$target);
        $normalizedRoot=str_replace('\\','/',$root);
        if (!str_starts_with($normalized,$normalizedRoot.'/')
            || str_contains(substr($normalized,strlen($normalizedRoot)+1),'../') || !is_file($target)) {
            $this->error('Manifest privado inválido.'); return self::FAILURE;
        }
        $manifest=json_decode((string)file_get_contents($target),true);
        if (!is_array($manifest) || ($manifest['schema']??null)!=='finance-review-v1'
            || ($manifest['reviewed']??false)!==true || !is_array($manifest['mappings']??null)) {
            $this->error('El manifest requiere revisión y mappings explícitos.'); return self::FAILURE;
        }
        $actor=User::query()->find($manifest['actor_id']??null);
        if (!$actor?->is_active || !$actor->hasPermission('scope.manage')) {
            $this->error('Actor no autorizado.'); return self::FAILURE;
        }
        $sources=[
            'estimated'=>EstimatedExpense::query()->orderBy('id')->get()->map->toArray()->all(),
            'actual'=>ActualExpense::query()->orderBy('id')->get()->map->toArray()->all(),
            'invoices'=>Invoice::query()->orderBy('id')->get()->map->toArray()->all(),
            'teams'=>Team::query()->orderBy('id')->get()->map->toArray()->all(),
        ];
        if (!hash_equals((string)($manifest['source_hash']??''),hash('sha256',json_encode($sources,JSON_THROW_ON_ERROR)))) {
            $this->error('El origen cambió; genere una nueva previsualización.'); return self::FAILURE;
        }
        // No se infieren días, tipos ni bancos: sólo se aplican mappings revisados.
        DB::transaction(function () use ($manifest,$actor): void {
            foreach ($manifest['mappings'] as $mapping) {
                abort_unless(isset($mapping['trip_phase_id'],$mapping['invoice_type'])
                    && in_array($mapping['invoice_type'],['IC','MAT'],true),422);
                $existing=\App\Models\BillingCase::query()
                    ->where('trip_phase_id',(int)$mapping['trip_phase_id'])
                    ->where('invoice_type',$mapping['invoice_type'])->first();
                if ($existing) {
                    abort_unless(($existing->metadata['source_hash']??null)===$manifest['source_hash']
                        && ($existing->metadata['calculation_version']??null)==='legacy-unverified',409);
                    $case=$existing;
                } else {
                    $case=app(\App\Services\Finance\BillingCaseService::class)->create(
                        $actor,(int)$mapping['trip_phase_id'],[
                            'invoice_type'=>$mapping['invoice_type'],
                            'billing_mode'=>$mapping['billing_mode']??'standard',
                            'metadata'=>['calculation_version'=>'legacy-unverified',
                                'source_hash'=>$manifest['source_hash']],
                        ]);
                }
                app(\App\Services\Security\ScopeService::class)->authorize(
                    $actor,'budgets.manage',(int)$case->trip_phase_id);
                foreach($mapping['lines']??[] as $lineMapping) {
                    abort_unless(isset($lineMapping['source_kind'],$lineMapping['source_id'],
                        $lineMapping['bucket'],$lineMapping['section'],$lineMapping['arranged_eligible']),422);
                    $source=match($lineMapping['source_kind']) {
                        'estimated'=>EstimatedExpense::query()->findOrFail($lineMapping['source_id']),
                        'actual'=>ActualExpense::query()->findOrFail($lineMapping['source_id']),
                        default=>abort(422),
                    };
                    abort_unless((int)$source->trip_phase_id===(int)$case->trip_phase_id,422);
                    $foreign=$lineMapping['source_kind']==='estimated'
                        ? 'legacy_estimated_expense_id':'legacy_actual_expense_id';
                    $line=BudgetLine::query()->firstOrCreate([
                        'billing_case_id'=>$case->id,$foreign=>$source->id,
                    ],[
                        'catalog_key'=>null,'description_en'=>$source->description,
                        'description_es'=>null,'unit'=>$source->unit,
                        'section'=>$lineMapping['section'],'bucket'=>$lineMapping['bucket'],
                        'arranged_eligible'=>(bool)$lineMapping['arranged_eligible'],
                        'sort_order'=>(int)($lineMapping['sort_order']??$source->id),
                    ]);
                    $cost=(string)($lineMapping['verified_unit_cost']??'');
                    $quantity=(string)($lineMapping['verified_quantity']??'');
                    if ($cost!==''&&$quantity!=='') {
                        \App\Support\Decimal::valid($cost);
                        \App\Support\Decimal::valid($quantity);
                        $version=BudgetLineVersion::query()->firstOrCreate([
                            'budget_line_id'=>$line->id,
                            'stage'=>$lineMapping['source_kind']==='estimated'?'initial':'final',
                        ],['unit_cost'=>$cost,'quantity'=>$quantity]);
                        foreach($lineMapping['actual_expense_ids']??[] as $actualId) {
                            $actual=ActualExpense::query()->where('trip_phase_id',$case->trip_phase_id)
                                ->findOrFail($actualId);
                            DB::table('budget_version_actual_expense')->insertOrIgnore([
                                'budget_line_version_id'=>$version->id,'actual_expense_id'=>$actual->id,
                            ]);
                        }
                    }
                }
                foreach($mapping['invoice_ids']??[] as $invoiceId) {
                    $invoice=Invoice::query()->where('trip_phase_id',$case->trip_phase_id)
                        ->where('type',$case->invoice_type)->findOrFail($invoiceId);
                    abort_unless($invoice->billing_case_id===null
                        || (int)$invoice->billing_case_id===(int)$case->id,409);
                    if ($invoice->snapshot===null) {
                        $invoice->forceFill([
                            'billing_case_id'=>$case->id,
                            'calculation_version'=>'legacy-unverified',
                            'snapshot'=>[
                                'calculation_version'=>'legacy-unverified',
                                'legacy_source_hash'=>$manifest['source_hash'],
                                'original'=>[
                                    'code'=>$invoice->code,'type'=>$invoice->type,'stage'=>$invoice->stage,
                                    'status'=>$invoice->status,'total_dr'=>$invoice->total_dr,
                                    'total_wodr'=>$invoice->total_wodr,'grand_total'=>$invoice->grand_total,
                                    'balance_conciliation'=>$invoice->balance_conciliation,
                                    'pdf_file_id'=>$invoice->pdf_file_id,
                                ],
                            ],
                        ])->save();
                    }
                }
                if (array_key_exists('opening_credit',$mapping)) {
                    $opening=(string)$mapping['opening_credit'];
                    abort_unless(\App\Support\Decimal::money($opening)===$opening,422);
                    $teamId=TripPhase::query()->whereKey($case->trip_phase_id)->value('team_id');
                    DB::table('credit_accounts')->insertOrIgnore([
                        'team_id'=>$teamId,'currency'=>'USD','balance'=>'0.00',
                        'created_at'=>now(),'updated_at'=>now(),
                    ]);
                    $account=CreditAccount::query()->where('team_id',$teamId)
                        ->where('currency','USD')->lockForUpdate()->firstOrFail();
                    $sourceKey=hash('sha256',$manifest['source_hash'].'|opening|'.$teamId);
                    $existingOpening=CreditEntry::query()->where('source_key',$sourceKey)->first();
                    if ($existingOpening) abort_unless($existingOpening->amount===$opening,409);
                    else {
                        CreditEntry::query()->create([
                            'credit_account_id'=>$account->id,'billing_case_id'=>$case->id,
                            'kind'=>'opening','amount'=>$opening,
                            'idempotency_key'=>\Illuminate\Support\Str::uuid(),
                            'payload_hash'=>hash('sha256',$sourceKey.'|'.$opening),
                            'source_key'=>$sourceKey,
                            'reason'=>'Saldo legado revisado desde manifest.',
                            'created_by_id'=>$actor->id,
                        ]);
                        $account->forceFill(['balance'=>\App\Support\Decimal::money(
                            \App\Support\Decimal::add($account->balance,$opening))])->save();
                    }
                }
            }
        },3);
        $this->info('Mappings aplicados sin reemitir documentos.');
        return self::SUCCESS;
    }
}
