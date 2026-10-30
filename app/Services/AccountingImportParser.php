<?php
declare(strict_types=1);
namespace App\Services;

use App\Support\Decimal;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use RuntimeException;

final class AccountingImportParser
{
    /** @return array<int,array<string,mixed>> */
    public function parse(UploadedFile $file): array
    {
        $extension=strtolower((string)$file->getClientOriginalExtension());
        if (!in_array($extension,['csv','txt'],true))
            throw new InvalidArgumentException('Este parser sólo acepta CSV histórico.');
        if ($file->getSize()>5242880) throw new RuntimeException('Archivo demasiado grande.');
        $handle=fopen($file->getRealPath(),'rb');
        if ($handle===false) throw new RuntimeException('No se pudo leer el archivo.');
        try {
            while (($line=fgets($handle,65537))!==false) {
                if (strlen($line)>=65536 && !str_ends_with($line,"\n"))
                    throw new RuntimeException('Línea CSV demasiado larga.');
            }
            rewind($handle);
            $headers=null; $rows=[];
            while(($data=fgetcsv($handle,65536,',','"',''))!==false) {
                if (count($data)>100) throw new RuntimeException('Demasiadas columnas.');
                foreach ($data as $cell) {
                    if (strlen((string)$cell)>4096 || !mb_check_encoding((string)$cell,'UTF-8'))
                        throw new RuntimeException('Celda CSV no válida.');
                }
                if($headers===null) { $headers=$this->headers($data); continue; }
                if(count($rows)>=5000) throw new RuntimeException('Demasiadas filas.');
                $row=$this->mapRow($headers,$data);
                if($row['code']==='') throw new RuntimeException('Fila sin código de invoice.');
                $rows[]=$row;
            }
            return $rows;
        } finally { fclose($handle); }
    }
    private function headers(array $data): array
    {
        $headers=array_map(fn($v)=>$this->normalizeHeader((string)$v),$data);
        if (count(array_unique($headers))!==count($headers) || !in_array('code',$headers,true))
            throw new RuntimeException('Cabeceras CSV inválidas.');
        return $headers;
    }
    private function normalizeHeader(string $header): string
    {
        $value=str($header)->lower()->ascii()->replaceMatches('/[^a-z0-9]+/','_')->trim('_')->toString();
        return match($value) {
            'invoice','invoice_code','codigo','codigo_invoice','code'=>'code',
            'estado','estado_contable','accounting_status'=>'accounting_status',
            'dr','total_dr','dr_total'=>'total_dr',
            'wodr','total_wodr','wodr_total'=>'total_wodr',
            'balance','balance_conciliation','conciliacion'=>'balance_conciliation',
            'nota','note','accounting_note','nota_contable'=>'accounting_note',
            default=>$value,
        };
    }
    private function mapRow(array $headers,array $data): array
    {
        $row=[];
        foreach($headers as $index=>$header) if($header!=='') $row[$header]=trim((string)($data[$index]??''));
        $status=str($row['accounting_status']??'')->lower()->ascii()->trim()->toString();
        return [
            'code'=>$row['code']??'',
            'accounting_status'=>match($status) {
                'pending','pendiente'=>'pending','reconciled','reconciliado','conciliado'=>'reconciled',
                'flagged','observado','observada'=>'flagged',default=>null,
            },
            'total_dr'=>$this->number($row['total_dr']??null),
            'total_wodr'=>$this->number($row['total_wodr']??null),
            'balance_conciliation'=>$this->number($row['balance_conciliation']??null),
            'accounting_note'=>$row['accounting_note']??null,
        ];
    }
    private function number(?string $value): ?string
    {
        if($value===null || $value==='') return null;
        if(str_contains($value,',') || str_contains($value,'$'))
            throw new InvalidArgumentException('Importe ambiguo; indique un formato decimal con punto.');
        return Decimal::money(Decimal::valid($value));
    }
}
