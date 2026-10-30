<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BillingCase;
use App\Models\BudgetLine;
use App\Models\BudgetLineVersion;
use App\Models\Invoice;
use App\Models\TripPhase;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class FinancialSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_esquema_documental_y_relaciones(): void
    {
        foreach (['billing_cases', 'billing_case_codes', 'invoice_sequences', 'budget_lines',
            'budget_line_versions', 'budget_cells'] as $table) {
            self::assertTrue(Schema::hasTable($table), $table);
        }
        self::assertTrue(Schema::hasColumns('billing_cases', [
            'trip_phase_id', 'invoice_type', 'version', 'current_initial_id', 'current_final_id', 'metadata',
        ]));
        self::assertTrue(Schema::hasColumns('invoices', [
            'billing_case_id', 'initial_invoice_id', 'snapshot', 'calculation_version', 'version',
            'issued_on', 'due_on', 'idempotency_key', 'payload_hash',
        ]));
        self::assertInstanceOf(BelongsTo::class, (new BillingCase())->tripPhase());
        self::assertInstanceOf(HasMany::class, (new BillingCase())->lines());
        self::assertInstanceOf(HasMany::class, (new BudgetLine())->versions());
        self::assertInstanceOf(HasMany::class, (new BudgetLineVersion())->cells());
        self::assertInstanceOf(BelongsTo::class, (new Invoice())->billingCase());
        self::assertInstanceOf(HasMany::class, (new TripPhase())->billingCases());
    }
}
