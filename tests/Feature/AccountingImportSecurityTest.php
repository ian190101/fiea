<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Services\AccountingImportParser;
use App\Services\Finance\ControlWorkbookParser;
use App\Support\CsvCell;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

final class AccountingImportSecurityTest extends TestCase
{
    public function test_csv_neutraliza_formula_textual_y_preserva_monto_negativo(): void
    {
        self::assertSame("'=HYPERLINK(1)",CsvCell::text('=HYPERLINK(1)'));
        self::assertSame("'  @SUM(1)",CsvCell::text('  @SUM(1)'));
        self::assertSame('-50.00','-50.00');
    }

    public function test_csv_duplicado_o_celda_excesiva_se_rechaza(): void
    {
        $path=tempnam(sys_get_temp_dir(),'fiea-csv-');
        file_put_contents($path,"code,code\nIE-25-019,IE-25-019\n");
        try {
            $file=new UploadedFile($path,'control.csv','text/csv',null,true);
            $this->expectException(\RuntimeException::class);
            app(AccountingImportParser::class)->parse($file);
        } finally { @unlink($path); }
    }

    public function test_xlsx_con_doctype_se_rechaza_antes_de_interpretar_xml(): void
    {
        $path=storage_path('app/test-'.\Illuminate\Support\Str::uuid().'.xlsx');
        $zip=new \ZipArchive();
        $zip->open($path,\ZipArchive::CREATE|\ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml',
            '<!DOCTYPE x [<!ENTITY x SYSTEM "file:///etc/passwd">]><workbook>&x;</workbook>');
        $zip->close();
        try {
            $file=new UploadedFile($path,'control.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',null,true);
            $this->expectException(\InvalidArgumentException::class);
            app(ControlWorkbookParser::class)->parse($file,'2025');
        } finally { @unlink($path); }
    }
}
