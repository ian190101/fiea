<?php
declare(strict_types=1);
namespace Tests\Feature;
use App\Models\Invoice;
use App\Support\MoneyPresenter;
use Barryvdh\DomPDF\Facade\Pdf;
use Tests\TestCase;
final class InvoiceFinancialPdfTest extends TestCase
{
    public function test_invoice_externa_renderiza_snapshot_y_excluye_notas_internas(): void
    {
        $invoice=new Invoice();
        $invoice->forceFill(['code'=>'IE-25-019','status'=>'approved',
            'accounting_note'=>'NOTA INTERNA NO PUBLICAR']);
        $initial=$this->snapshot('initial','633.00','633.00');
        $final=$this->snapshot('final','583.00','-50.00');
        $final['initial_snapshot']=$initial;
        $final['initial_total']='633.00';
        $html=view('pdf.invoice',['invoice'=>$invoice,'snapshot'=>$final])->render();
        self::assertStringContainsString('IE-25-019',$html);
        self::assertStringContainsString('$583.00',$html);
        self::assertStringContainsString('$633.00',$html);
        self::assertStringContainsString('-$50.00',$html);
        self::assertStringContainsString('001-100-000000083',$html);
        self::assertStringNotContainsString('NOTA INTERNA NO PUBLICAR',$html);
        self::assertSame('-$1,234.50',MoneyPresenter::format('-1234.50'));
        self::assertStringStartsWith('%PDF',Pdf::loadView('pdf.invoice',[
            'invoice'=>$invoice,'snapshot'=>$final,
        ])->setPaper('letter','portrait')->output());
    }

    public function test_matriz_imprime_bloque_de_siete_dias(): void
    {
        $snapshot=$this->snapshot('initial','633.00','633.00');
        $days=['2026-11-01','2026-11-02','2026-11-03','2026-11-04',
            '2026-11-05','2026-11-06','2026-11-07'];
        $html=view('pdf.draft-budget',['tripPhase'=>null,
            'sheets'=>[['type'=>'IC','stage'=>'initial','snapshot'=>$snapshot,'days'=>$days]]])->render();
        foreach($days as $day) self::assertStringContainsString($day,$html);
        self::assertStringContainsString('001-100-000000083',$html);
        self::assertStringStartsWith('%PDF',Pdf::loadView('pdf.draft-budget',[
            'tripPhase'=>null,'sheets'=>[['type'=>'IC','stage'=>'initial',
                'snapshot'=>$snapshot,'days'=>$days]],
        ])->setPaper('letter','landscape')->output());
    }

    private function snapshot(string $stage,string $total,string $balance): array
    {
        return [
            'type'=>'IC','stage'=>$stage,
            'metadata'=>['project_name'=>'Proyecto prueba','team_name'=>'Equipo prueba',
                'starts_on'=>'2026-11-01','ends_on'=>'2026-11-07','invoice_name'=>'Contacto',
                'email'=>'contacto@example.test'],
            'lines'=>[['description_en'=>'Flat Fee','description_es'=>'Tarifa fija',
                'unit'=>'day','unit_cost'=>'500.0000','raw_amount'=>'500.00',
                'receipt_number'=>'001-100-000000083','cells'=>[
                    ['date'=>'2026-11-01','quantity'=>'1.0000','receipt_number'=>'001-100-000000083'],
                ]]],
            'totals'=>['totalDr'=>$stage==='initial'?'550.00':'500.00',
                'totalWodr'=>'83.00','total'=>$total,'balanceDue'=>$balance,
                'serviceFee'=>'3.00','bankFee'=>'80.00','contingency'=>$stage==='initial'?'50.00':'0.00'],
            'payment_terms'=>['lead_time'=>'El pago debe recibirse 14 días antes de la llegada.'],
        ];
    }
}
