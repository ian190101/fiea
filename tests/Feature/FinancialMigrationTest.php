<?php
declare(strict_types=1);
namespace Tests\Feature;
use App\Models\BillingCase;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
final class FinancialMigrationTest extends TestCase
{
    use RefreshDatabase;
    public function test_preview_no_escribe_finanzas_y_apply_exige_manifest_revisado(): void
    {
        $this->seed();
        $actor=User::query()->create(['name'=>'Migrador','username'=>'migrador',
            'password'=>'ClaveTemporal123!','is_active'=>true,'must_change_password'=>false]);
        $actor->forceFill(['scope_all'=>true])->save();
        $actor->roles()->attach(Role::query()->where('code','superadmin')->value('id'));
        $path=storage_path('app/private/finance-test-review.json');
        try {
            $this->artisan('finance:migration-preview',['--output'=>$path])->assertSuccessful();
            self::assertSame(0,BillingCase::query()->count());
            $manifest=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
            self::assertSame(false,$manifest['reviewed']);
            $this->artisan('finance:migration-apply',['--manifest'=>$path])->assertFailed();
            $manifest['reviewed']=true;
            $manifest['actor_id']=$actor->id;
            $correctHash=$manifest['source_hash'];
            $manifest['source_hash']=str_repeat('0',64);
            file_put_contents($path,json_encode($manifest,JSON_THROW_ON_ERROR));
            $this->artisan('finance:migration-apply',['--manifest'=>$path])->assertFailed();
            $manifest['source_hash']=$correctHash;
            file_put_contents($path,json_encode($manifest,JSON_THROW_ON_ERROR));
            $this->artisan('finance:migration-apply',['--manifest'=>$path])->assertSuccessful();
            $this->artisan('finance:migration-apply',['--manifest'=>$path])->assertSuccessful();
            self::assertSame(0,BillingCase::query()->count());
        } finally { @unlink($path); }
    }
}
