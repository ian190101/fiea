<?php
declare(strict_types=1);
namespace App\Console\Commands;

use App\Models\ActualExpense;
use App\Models\EstimatedExpense;
use App\Models\Invoice;
use App\Models\Team;
use App\Support\Decimal as D;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

final class PreviewFinancialMigration extends Command
{
    protected $signature='finance:migration-preview {--output=storage/app/private/finance-review.json}';
    protected $description='Previsualiza el legado financiero sin modificar datos';

    public function handle(): int
    {
        $sources=[
            'estimated'=>EstimatedExpense::query()->orderBy('id')->get()->map->toArray()->all(),
            'actual'=>ActualExpense::query()->orderBy('id')->get()->map->toArray()->all(),
            'invoices'=>Invoice::query()->orderBy('id')->get()->map->toArray()->all(),
            'teams'=>Team::query()->orderBy('id')->get()->map->toArray()->all(),
        ];
        $sourceHash=hash('sha256',json_encode($sources,JSON_THROW_ON_ERROR));
        $manifest=[
            'schema'=>'finance-review-v1','source_hash'=>$sourceHash,
            'generated_at'=>now()->toISOString(),
            'estimated_total'=>D::money(D::sum(array_map(fn($v)=>(string)$v['estimated_total'],$sources['estimated']))),
            'actual_total'=>D::money(D::sum(array_map(fn($v)=>(string)$v['real_total'],$sources['actual']))),
            'counts'=>array_map('count',$sources),
            'unmapped'=>[
                'estimated'=>array_map(fn($v)=>['id'=>$v['id'],'trip_phase_id'=>$v['trip_phase_id'],
                    'estimated_total'=>(string)$v['estimated_total']],$sources['estimated']),
                'actual'=>array_map(fn($v)=>['id'=>$v['id'],'trip_phase_id'=>$v['trip_phase_id'],
                    'real_total'=>(string)$v['real_total']],$sources['actual']),
                'invoices'=>array_map(fn($v)=>['id'=>$v['id'],'trip_phase_id'=>$v['trip_phase_id'],
                    'type'=>$v['type'],'stage'=>$v['stage'],'status'=>$v['status'],
                    'grand_total'=>(string)$v['grand_total'],'pdf_file_id'=>$v['pdf_file_id']],$sources['invoices']),
                'teams'=>array_map(fn($v)=>['id'=>$v['id'],
                    'credit_balance'=>(string)$v['credit_balance']],$sources['teams']),
            ],
            'mappings'=>[],
            'actor_id'=>null,
            'reviewed'=>false,
        ];
        $path=(string)$this->option('output');
        $root=storage_path('app/private');
        $target=str_starts_with($path,'storage/app/private/')
            ? storage_path('app/private/'.substr($path,20)) : $path;
        $normalized=str_replace('\\','/',$target);
        $normalizedRoot=str_replace('\\','/',$root);
        abort_unless(str_starts_with($normalized,$normalizedRoot.'/')
            && !str_contains(substr($normalized,strlen($normalizedRoot)+1),'../'),422);
        if (!is_dir(dirname($target))) mkdir(dirname($target),0770,true);
        file_put_contents($target,json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        $this->info('Manifest de revisión creado: '.$target);
        return self::SUCCESS;
    }
}
