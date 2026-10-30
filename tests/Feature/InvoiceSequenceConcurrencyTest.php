<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BillingCase;
use App\Services\Finance\InvoiceCodeAllocator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class InvoiceSequenceConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_indice_unico_impide_dos_contadores_de_una_serie(): void
    {
        $now = now();
        DB::table('invoice_sequences')->insert([
            'series' => 'IE', 'year' => 2026, 'last_number' => 19,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->expectException(QueryException::class);
        DB::table('invoice_sequences')->insert([
            'series' => 'IE', 'year' => 2026, 'last_number' => 19,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function test_allocator_reserva_serie_compartida_por_tipo(): void
    {
        $now = now();
        $chapterTypeId = DB::table('chapter_types')->insertGetId(['name' => 'Universitario', 'created_at' => $now, 'updated_at' => $now]);
        $countryId = DB::table('countries')->insertGetId(['name' => 'Ecuador', 'created_at' => $now, 'updated_at' => $now]);
        $chapterId = DB::table('chapters')->insertGetId(['chapter_type_id' => $chapterTypeId, 'name' => 'Capítulo', 'created_at' => $now, 'updated_at' => $now]);
        $teamId = DB::table('teams')->insertGetId(['chapter_id' => $chapterId, 'name' => 'Equipo', 'credit_balance' => '0.00', 'created_at' => $now, 'updated_at' => $now]);
        $projectId = DB::table('projects')->insertGetId(['country_id' => $countryId, 'code' => 'P-01', 'name' => 'Proyecto', 'created_at' => $now, 'updated_at' => $now]);
        $phaseId = DB::table('trip_phases')->insertGetId([
            'project_id' => $projectId, 'team_id' => $teamId, 'phase' => 'Initial Visit',
            'starts_on' => '2026-11-01', 'ends_on' => '2026-11-02',
            'volunteer_count' => 1, 'staff_count' => 1, 'status' => 'draft',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $ic = BillingCase::query()->create(['trip_phase_id' => $phaseId, 'invoice_type' => 'IC',
            'metadata' => ['document_date' => '2026-10-26']]);
        $mat = BillingCase::query()->create(['trip_phase_id' => $phaseId, 'invoice_type' => 'MAT',
            'metadata' => ['document_date' => '2026-10-26']]);
        $allocator = new InvoiceCodeAllocator();
        self::assertSame('IE-26-001', $allocator->allocate($ic, 'initial'));
        self::assertSame('IE-26-001-F', $allocator->allocate($ic, 'final'));
        self::assertSame('IE-26-002', $allocator->allocate($mat, 'initial'));
    }
}
