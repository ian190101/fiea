<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Models\BillingCase;
use App\Models\BillingCaseCode;
use App\Models\Role;
use App\Models\TreasuryPayment;
use App\Models\AccountingReview;
use App\Models\User;
use App\Services\Finance\ControlImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use ZipArchive;

final class ControlImportApplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_no_crea_caja_y_apply_repetido_no_duplica_pago(): void
    {
        [$actor,$case]=$this->scenario();
        Storage::fake('local');
        $path=$this->workbook();
        try {
            $file=new UploadedFile($path,'control.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',null,true);
            $service=app(ControlImportService::class);
            $import=$service->preview($actor,$file,'2026');
            self::assertSame(0,TreasuryPayment::query()->count());
            $row=$import->rows()->firstOrFail();
            self::assertSame($case->id,$row->target_case_id);
            try {
                $service->apply($actor,$import->id,[$row->id]);
                self::fail('Pago sin referencia no comprobado.');
            } catch(HttpException $exception) { self::assertSame(422,$exception->getStatusCode()); }
            $row->forceFill(['resolutions'=>['reference'=>'REF-20','method'=>'ACH','stage'=>'initial']])->save();
            $service->apply($actor,$import->id,[$row->id]);
            $service->apply($actor,$import->id,[$row->id]);
            self::assertSame(1,TreasuryPayment::query()->count());
            self::assertSame('20.00',TreasuryPayment::query()->firstOrFail()->amount);
            self::assertSame(3,$case->fresh()->version);
            $again=$service->preview($actor,$file,'2026');
            self::assertSame($import->id,$again->id);
            $group=$again->rows()->where('section','control_group')->firstOrFail();
            self::assertSame(3,$group->cases()->firstOrFail()->pivot->expected_version);
        } finally { @unlink($path); }
    }

    public function test_grupo_contable_sin_final_conserva_operativo_pendiente(): void
    {
        [$actor,$case]=$this->scenario();
        Storage::fake('local');
        $path=$this->workbook();
        try {
            $file=new UploadedFile($path,'control.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',null,true);
            $service=app(ControlImportService::class);
            $import=$service->preview($actor,$file,'2026');
            $row=$import->rows()->where('section','control_group')->firstOrFail();
            $row->forceFill(['resolutions'=>['project_id'=>$case->tripPhase->project_id]])->save();
            $service->apply($actor,$import->id,[$row->id]);
            $review=AccountingReview::query()->firstOrFail();
            self::assertSame('1900.00',$review->accounting_total);
            self::assertNull($review->operating_total);
            self::assertNull($review->difference);
            self::assertSame([$case->id],$review->source['case_ids']);
        } finally { @unlink($path); }
    }

    public function test_caso_y_grupo_del_mismo_archivo_se_aplican_en_un_lote(): void
    {
        [$actor,$case]=$this->scenario();
        Storage::fake('local');
        $path=$this->workbook();
        try {
            $file=new UploadedFile($path,'control.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',null,true);
            $service=app(ControlImportService::class);
            $import=$service->preview($actor,$file,'2026');
            $caseRow=$import->rows()->where('section','case')->firstOrFail();
            $groupRow=$import->rows()->where('section','control_group')->firstOrFail();
            $caseRow->forceFill(['resolutions'=>['reference'=>'REF-L','method'=>'ACH','stage'=>'initial']])->save();
            $groupRow->forceFill(['resolutions'=>['project_id'=>$case->tripPhase->project_id]])->save();
            $service->apply($actor,$import->id,[$caseRow->id,$groupRow->id]);
            self::assertSame(1,TreasuryPayment::query()->count());
            self::assertSame(1,AccountingReview::query()->count());
            self::assertSame(3,$case->fresh()->version);
        } finally { @unlink($path); }
    }

    private function scenario(): array
    {
        $this->seed(); $now=now();
        $chapter=DB::table('chapters')->insertGetId(['chapter_type_id'=>DB::table('chapter_types')->value('id'),
            'name'=>'Capítulo import','created_at'=>$now,'updated_at'=>$now]);
        $team=DB::table('teams')->insertGetId(['chapter_id'=>$chapter,'name'=>'Equipo import',
            'credit_balance'=>'0.00','created_at'=>$now,'updated_at'=>$now]);
        $project=DB::table('projects')->insertGetId(['country_id'=>DB::table('countries')->value('id'),
            'code'=>'IM-01','name'=>'Proyecto import','created_at'=>$now,'updated_at'=>$now]);
        $phase=DB::table('trip_phases')->insertGetId(['project_id'=>$project,'team_id'=>$team,
            'phase'=>'Initial Visit','starts_on'=>'2026-10-20','ends_on'=>'2026-10-23',
            'volunteer_count'=>1,'staff_count'=>1,'status'=>'draft','created_at'=>$now,'updated_at'=>$now]);
        $actor=User::query()->create(['name'=>'Importador','username'=>'importador',
            'password'=>'ClaveTemporal123!','is_active'=>true,'must_change_password'=>false]);
        $actor->forceFill(['scope_all'=>true])->save();
        $actor->roles()->attach(Role::query()->where('code','superadmin')->value('id'));
        $case=BillingCase::query()->create(['trip_phase_id'=>$phase,'invoice_type'=>'IC',
            'billing_mode'=>'standard','metadata'=>[]]);
        BillingCaseCode::query()->create(['billing_case_id'=>$case->id,'code'=>'IE-26-IMPORT','year'=>2026]);
        return [$actor,$case];
    }

    private function workbook(): string
    {
        $directory=storage_path('app/private');
        if(!is_dir($directory)) mkdir($directory,0770,true);
        $path=tempnam($directory,'import-');
        $zip=new ZipArchive();
        self::assertTrue($zip->open($path,ZipArchive::OVERWRITE)===true);
        $zip->addFromString('xl/workbook.xml','<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="2026" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels','<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
        $cell=fn($ref,$value)=>'<c r="'.$ref.'" t="inlineStr"><is><t>'.$value.'</t></is></c>';
        $sheet='<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .'<row r="2">'.$cell('A2','STATUS').$cell('B2','INVOICE').'</row>'
            .'<row r="3">'.$cell('A3','ABIERTO').$cell('B3','IE-26-IMPORT')
            .$cell('C3','IC').$cell('T3','20.00').$cell('U3','20/10/2026')
            .$cell('AB3','IE-26-IMPORT').$cell('AC3','IM-01')
            .$cell('AD3','20.00').$cell('AG3','1900.00').'</row>'
            .'</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml',$sheet);
        $zip->close();
        return $path;
    }
}
