<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Security\AuditWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FinancialAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_evento_se_confirma_con_mutacion_y_omite_secretos(): void
    {
        $actor=User::factory()->create();
        $id=(string)Str::uuid();
        DB::transaction(function () use ($actor,$id): void {
            app(AuditWriter::class)->record($actor,'receipt_updated',$actor,null,
                ['amount'=>'10.00','password'=>'secreto'],
                ['amount'=>'11.00','version'=>2,'password'=>'secreto'],$id);
        });
        $log=AuditLog::query()->firstOrFail();
        self::assertSame($id,$log->correlation_id);
        self::assertSame('11.00',$log->metadata['after']['amount']);
        self::assertArrayNotHasKey('password',$log->metadata['before']);
        self::assertArrayNotHasKey('password',$log->metadata['after']);
    }

    public function test_rollback_no_deja_evento_de_exito(): void
    {
        $actor=User::factory()->create();
        try {
            DB::transaction(function () use ($actor): void {
                app(AuditWriter::class)->record($actor,'receipt_stored',$actor,null,[],
                    ['amount'=>'20.00'],(string)Str::uuid());
                throw new \RuntimeException('fallo intencionado');
            });
        } catch (\RuntimeException) {}
        self::assertDatabaseCount('audit_logs',0);
    }
}
