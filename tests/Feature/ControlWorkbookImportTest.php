<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Services\Finance\ControlWorkbookParser;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use ZipArchive;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ControlWorkbookImportTest extends TestCase
{
    public function test_zip_expansivo_se_rechaza_antes_de_parsear_xml(): void
    {
        $directory=storage_path('app/private');
        if(!is_dir($directory)) mkdir($directory,0770,true);
        $path=tempnam($directory,'control-');
        $zip=new ZipArchive();
        self::assertTrue($zip->open($path,ZipArchive::OVERWRITE)===true);
        $zip->addFromString('xl/workbook.xml',str_repeat('x',10485761));
        $zip->close();
        try {
            $file=new UploadedFile($path,'control.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',null,true);
            try {
                app(ControlWorkbookParser::class)->parse($file,'2026');
                self::fail('No debe descomprimirse XML mayor al límite.');
            } catch(HttpException $exception) {
                self::assertSame(422,$exception->getStatusCode());
            }
        } finally { @unlink($path); }
    }

    public function test_fecha_1904_y_formula_sin_cache_se_marcan_sin_evaluar(): void
    {
        $directory=storage_path('app/private');
        if(!is_dir($directory)) mkdir($directory,0770,true);
        $path=tempnam($directory,'control-');
        $zip=new ZipArchive();
        self::assertTrue($zip->open($path,ZipArchive::OVERWRITE)===true);
        $zip->addFromString('xl/workbook.xml','<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><workbookPr date1904="1"/><sheets><sheet name="2026" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels','<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml','<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="2"><c r="A2" t="inlineStr"><is><t>STATUS</t></is></c><c r="B2" t="inlineStr"><is><t>INVOICE</t></is></c></row><row r="3"><c r="A3" t="inlineStr"><is><t>ABIERTO</t></is></c><c r="B3" t="inlineStr"><is><t>IE-26-003</t></is></c><c r="J3"><f>1+1</f></c><c r="U3"><v>0</v></c></row></sheetData></worksheet>');
        $zip->close();
        try {
            $file=new UploadedFile($path,'control.xlsx','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',null,true);
            $rows=app(ControlWorkbookParser::class)->parse($file,'2026');
            self::assertSame('1904-01-01',$rows[0]->normalized['U']);
            self::assertContains('Fórmula sin valor cacheado en J3',$rows[0]->errors);
        } finally { @unlink($path); }
    }

    public function test_resuelve_hoja_por_nombre_y_preserva_cadenas_decimales(): void
    {
        $directory=storage_path('app/private');
        if (!is_dir($directory)) mkdir($directory,0770,true);
        $path=tempnam($directory,'control-');
        $zip=new ZipArchive();
        self::assertTrue($zip->open($path,ZipArchive::OVERWRITE)===true);
        $zip->addFromString('xl/workbook.xml','<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="2025" sheetId="1" r:id="rId1"/><sheet name="2026" sheetId="2" r:id="rId2"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels','<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Target="worksheets/sheet2.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml','<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="2"><c r="A2" t="inlineStr"><is><t>STATUS</t></is></c><c r="B2" t="inlineStr"><is><t>INVOICE</t></is></c></row><row r="3"><c r="A3" t="inlineStr"><is><t>CERRADO</t></is></c><c r="B3" t="inlineStr"><is><t>IE-25-019</t></is></c><c r="S3"><v>633.000</v></c><c r="V3"><v>580.000</v></c></row></sheetData></worksheet>');
        $cells=function(array $values,int $row): string {
            $xml='';
            foreach($values as $column=>$value) {
                $xml.='<c r="'.$column.$row.'" t="inlineStr"><is><t>'.htmlspecialchars($value,ENT_XML1).'</t></is></c>';
            }
            return '<row r="'.$row.'">'.$xml.'</row>';
        };
        $sheet='<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .$cells(['A'=>'STATUS','B'=>'INVOICE','C'=>'TIPO','AB'=>'CÓDIGOS INVOICES'],2)
            .$cells(['A'=>'ABIERTO','B'=>'IE-26-001 & IE-26-002','C'=>'IC',
                'D'=>'NO INITIAL SOLO FINAL','J'=>'500.000','K'=>'83.000','L'=>'583.000',
                'AB'=>'IE-26-001 & IE-26-002','AC'=>'Proyecto','AD'=>'583.000'],3)
            .'</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet2.xml',$sheet);
        $zip->close();
        try {
            $file=new UploadedFile($path,'control.xlsx','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',null,true);
            $rows=app(ControlWorkbookParser::class)->parse($file,'2026');
            self::assertCount(2,$rows);
            self::assertSame('final_only',$rows[0]->normalized['billing_mode']);
            self::assertSame(['IE-26-001','IE-26-002'],$rows[0]->normalized['codes']);
            self::assertSame('583.00',$rows[0]->normalized['L']);
            self::assertSame('control_group',$rows[1]->section);
            self::assertSame('583.00',$rows[1]->normalized['AD']);
            $previous=app(ControlWorkbookParser::class)->parse($file,'2025');
            self::assertCount(1,$previous);
            self::assertSame('633.00',$previous[0]->normalized['S']);
            self::assertSame('580.00',$previous[0]->normalized['V']);
        } finally { @unlink($path); }
    }
}
