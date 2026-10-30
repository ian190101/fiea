<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\Models\AccountingReview;
use App\Models\BillingCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
final class FinancialOperationController extends Controller
{
    public function show(Request $request,BillingCase $billingCase,string $operation): Response
    {
        $spec=match($operation) {
            'payment'=>['payment','post','treasury.payments.store','Registrar pago',true],
            'expectation'=>['payment','patch','treasury.expectations.update','Actualizar expectativa',true],
            'credit'=>['credit','post','credits.apply','Aplicar crédito',true],
            'transition'=>['transition','post','billing-cases.transition','Cambiar estado',true],
            'settings'=>['update','patch','billing-cases.settings','Configurar expediente',true],
            default=>abort(404),
        };
        Gate::forUser($request->user())->authorize($spec[0],$billingCase);
        $url=$operation==='expectation'
            ? route($spec[2],[$billingCase,'eia_to_fiea']) : route($spec[2],$billingCase);
        $fields=match($operation) {
            'payment'=>['leg','stage','direction','amount','paid_on','method','reference','note'],
            'expectation'=>['stage','expected_amount','basis_invoice_id','reason'],
            'credit'=>['amount'], 'transition'=>['target','reason'],
            'settings'=>['bank_fee_initial','bank_fee_final','final_contingency','reason'],
        };
        if ($operation==='settings') {
            $lockedInitial=$billingCase->currentInitial
                && in_array($billingCase->currentInitial->status,['approved','sent','paid'],true);
            $lockedFinal=$billingCase->currentFinal
                && in_array($billingCase->currentFinal->status,['approved','sent','paid'],true);
            $fields=array_values(array_filter($fields,fn(string $field)=>
                !($lockedInitial&&$field==='bank_fee_initial')
                && !($lockedFinal&&in_array($field,['bank_fee_final','final_contingency'],true))));
        }
        $types=['amount'=>'decimal','expected_amount'=>'decimal','bank_fee_initial'=>'decimal',
            'bank_fee_final'=>'decimal','final_contingency'=>'decimal','paid_on'=>'date',
            'leg'=>'select','stage'=>'select','direction'=>'select','target'=>'select',
            'method'=>'select','basis_invoice_id'=>'select',
            'note'=>'textarea','reason'=>'textarea'];
        $options=[
            'leg'=>['chapter_to_eia'=>'Capítulo a EIA','eia_to_fiea'=>'EIA a FIEA'],
            'stage'=>['initial'=>'Initial','final'=>'Final'],
            'direction'=>['receipt'=>'Cobro','refund'=>'Devolución'],
            'target'=>['POR GASTAR'=>'Por gastar','FINAL PEND'=>'Final pendiente','CERRADO'=>'Cerrado','ANULADO'=>'Anulado'],
            'method'=>['CHECK'=>'Cheque','ACH'=>'ACH','CREDIT_CARD'=>'Tarjeta',
                'PAYPAL'=>'PayPal','TRANSFER'=>'Transferencia','OTHER'=>'Otro'],
        ];
        $allowedTargets=match($billingCase->lifecycle_status) {
            'ABIERTO'=>['POR GASTAR','FINAL PEND','ANULADO'],
            'POR GASTAR'=>['FINAL PEND','ANULADO'],
            'FINAL PEND'=>['CERRADO','ANULADO'],
            default=>[],
        };
        $options['target']=array_intersect_key($options['target'],array_flip($allowedTargets));
        $options['basis_invoice_id']=collect([$billingCase->currentInitial,$billingCase->currentFinal])
            ->filter(fn($invoice)=>$invoice&&in_array($invoice->status,['approved','sent','paid'],true))
            ->mapWithKeys(fn($invoice)=>[$invoice->id=>$invoice->code.' '.$invoice->stage])->all();
        $formFields=array_map(fn(string $name)=>[
            'name'=>$name,'label'=>str_replace('_',' ',ucfirst($name)),
            'type'=>$types[$name]??'text','required'=>!in_array($name,['reference','note','basis_invoice_id','final_contingency','reason'],true),
            'options'=>array_map(fn($value,$label)=>['value'=>$value,'label'=>$label],
                array_keys($options[$name]??[]),array_values($options[$name]??[])),
            'hint'=>null,
        ],$fields);
        $initial=['version'=>$billingCase->version,'idempotency_key'=>(string)Str::uuid()];
        foreach($fields as $field) $initial[$field]=match($field) {
            'bank_fee_initial','bank_fee_final','final_contingency'=>$billingCase->{$field},
            default=>null,
        };
        return Inertia::render('Accounting/Operation',[
            'title'=>$spec[3],'description'=>'Expediente '.$billingCase->invoice_type,
            'action'=>['method'=>$spec[1],'url'=>$url,'label'=>$spec[3],
                'requires_confirmation'=>$spec[4]],
            'return_url'=>$request->user()->hasAnyPermission(['accounting.view','accounting.manage'])
                ?route('accounting.control'):route('dashboard'),
            'initialData'=>$initial,'fields'=>$formFields,
        ]);
    }

    public function editReview(Request $request,AccountingReview $accountingReview): Response
    {
        Gate::forUser($request->user())->authorize('update',$accountingReview);
        return Inertia::render('Accounting/Operation',[
            'title'=>'Revisión contable','description'=>'Conciliación del período '.$accountingReview->period_year,
            'action'=>['method'=>'patch','url'=>route('accounting-reviews.update',$accountingReview),
                'label'=>'Guardar revisión','requires_confirmation'=>true],
            'return_url'=>route('accounting.control'),
            'initialData'=>['version'=>$accountingReview->version,
                'accounting_total'=>$accountingReview->accounting_total,'note'=>$accountingReview->note],
            'fields'=>[
                ['name'=>'accounting_total','label'=>'Gasto contable','type'=>'decimal','required'=>false,'options'=>[],'hint'=>null],
                ['name'=>'note','label'=>'Nota','type'=>'textarea','required'=>false,'options'=>[],'hint'=>null],
            ],
        ]);
    }
}
