<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach ([
            'teams' => ['credit_balance'],
            'estimated_expenses' => ['estimated_total'],
            'actual_expenses' => ['real_total'],
            'receipts' => ['amount'],
            'invoices' => ['total_dr', 'total_wodr', 'grand_total', 'balance_conciliation'],
        ] as $name => $columns) {
            Schema::table($name, function (Blueprint $table) use ($columns): void {
                foreach ($columns as $column) {
                    $table->decimal($column, 15, 2)->default(0)->change();
                }
            });
        }
        foreach ([
            'estimated_expenses' => ['initial_unit_cost', 'initial_quantity'],
            'actual_expenses' => ['final_unit_cost', 'final_quantity'],
        ] as $name => $columns) {
            Schema::table($name, function (Blueprint $table) use ($columns): void {
                foreach ($columns as $column) {
                    $table->decimal($column, 15, 4)->default(0)->change();
                }
            });
        }

        Schema::create('billing_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trip_phase_id')->constrained('trip_phases')->restrictOnDelete();
            $table->string('invoice_type', 3);
            $table->string('billing_mode', 16)->default('standard');
            $table->string('lifecycle_status', 16)->default('ABIERTO');
            $table->unsignedBigInteger('version')->default(1);
            $table->decimal('bank_fee_initial', 15, 2)->default(80);
            $table->decimal('bank_fee_final', 15, 2)->default(80);
            $table->decimal('final_contingency', 15, 2)->nullable();
            $table->foreignId('current_initial_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->foreignId('current_final_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->unsignedBigInteger('current_code_id')->nullable();
            $table->json('metadata');
            $table->timestamp('closed_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();
            $table->unique(['trip_phase_id', 'invoice_type'], 'billing_cases_phase_type_uq');
            $table->index(['lifecycle_status', 'id'], 'billing_cases_status_id_idx');
        });

        Schema::create('billing_case_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('billing_case_id')->constrained('billing_cases')->restrictOnDelete();
            $table->string('code', 64)->unique();
            $table->unsignedSmallInteger('year');
            $table->text('source_note')->nullable();
            $table->timestamps();
            $table->index(['year', 'billing_case_id'], 'case_codes_year_case_idx');
        });
        Schema::table('billing_cases', function (Blueprint $table): void {
            $table->foreign('current_code_id', 'billing_cases_current_code_fk')
                ->references('id')->on('billing_case_codes')->restrictOnDelete();
        });

        Schema::create('invoice_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('series', 8);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['series', 'year'], 'invoice_sequences_series_year_uq');
        });

        Schema::create('budget_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('billing_case_id')->constrained('billing_cases')->restrictOnDelete();
            $table->string('catalog_key', 80)->nullable();
            $table->string('description_en', 255);
            $table->string('description_es', 255)->nullable();
            $table->string('unit', 80);
            $table->string('section', 40);
            $table->string('bucket', 16);
            $table->boolean('arranged_eligible')->default(false);
            $table->unsignedInteger('sort_order');
            $table->foreignId('legacy_estimated_expense_id')->nullable()->constrained('estimated_expenses')->restrictOnDelete();
            $table->foreignId('legacy_actual_expense_id')->nullable()->constrained('actual_expenses')->restrictOnDelete();
            $table->timestamps();
            $table->index(['billing_case_id', 'sort_order', 'id'], 'budget_lines_case_sort_idx');
            $table->unique(['billing_case_id', 'catalog_key'], 'budget_lines_case_catalog_uq');
        });

        Schema::create('budget_line_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('budget_line_id')->constrained('budget_lines')->restrictOnDelete();
            $table->string('stage', 7);
            $table->decimal('unit_cost', 15, 4);
            $table->decimal('quantity', 15, 4)->nullable();
            $table->string('receipt_number', 255)->nullable();
            $table->timestamps();
            $table->unique(['budget_line_id', 'stage'], 'budget_versions_line_stage_uq');
        });

        Schema::create('budget_cells', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('budget_line_version_id')->constrained('budget_line_versions')->restrictOnDelete();
            $table->date('spent_on');
            $table->decimal('quantity', 15, 4);
            $table->string('receipt_number', 255)->nullable();
            $table->timestamps();
            $table->unique(['budget_line_version_id', 'spent_on'], 'budget_cells_version_day_uq');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->foreignId('billing_case_id')->nullable()->constrained('billing_cases')->restrictOnDelete();
            $table->foreignId('initial_invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->json('snapshot')->nullable();
            $table->string('calculation_version', 40)->nullable();
            $table->unsignedBigInteger('version')->default(1);
            $table->date('issued_on')->nullable();
            $table->date('due_on')->nullable();
            $table->uuid('idempotency_key')->nullable()->unique('invoices_idempotency_key_uq');
            $table->char('payload_hash', 64)->nullable();
            $table->index(['billing_case_id', 'stage', 'status', 'id'], 'invoices_case_stage_status_idx');
        });

        Schema::table('billing_cases', fn (Blueprint $table) => $table->text('treasury_note')->nullable());

        Schema::create('payment_expectations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('billing_case_id')->constrained('billing_cases')->restrictOnDelete();
            $table->string('leg', 24);
            $table->string('stage', 7);
            $table->decimal('expected_amount', 15, 2);
            $table->string('basis', 24);
            $table->foreignId('basis_invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->json('source');
            $table->text('reason');
            $table->unsignedBigInteger('version')->default(1);
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['billing_case_id', 'leg', 'stage'], 'payment_expectations_case_leg_stage_uq');
        });

        Schema::create('treasury_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('billing_case_id')->constrained('billing_cases')->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->string('leg', 24);
            $table->string('stage', 7);
            $table->string('direction', 8);
            $table->decimal('amount', 15, 2);
            $table->date('paid_on');
            $table->string('method', 40);
            $table->string('reference', 255)->nullable();
            $table->text('note')->nullable();
            $table->uuid('idempotency_key')->unique('treasury_payments_idempotency_uq');
            $table->char('payload_hash', 64);
            $table->char('source_key', 64)->nullable()->unique('treasury_payments_source_key_uq');
            $table->foreignId('reverses_payment_id')->nullable()->constrained('treasury_payments')->restrictOnDelete();
            $table->unique('reverses_payment_id', 'treasury_payments_reverses_uq');
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['billing_case_id', 'leg', 'paid_on', 'id'], 'treasury_payments_case_leg_date_idx');
        });

        Schema::create('accounting_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('team_id')->constrained('teams')->restrictOnDelete();
            $table->unsignedSmallInteger('period_year');
            $table->decimal('operating_total', 15, 2)->nullable();
            $table->decimal('accounting_total', 15, 2)->nullable();
            $table->decimal('difference', 15, 2)->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('version')->default(1);
            $table->json('source');
            $table->foreignId('reviewed_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'team_id', 'period_year'], 'accounting_reviews_group_year_uq');
        });

        Schema::create('credit_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->restrictOnDelete();
            $table->char('currency', 3)->default('USD');
            $table->decimal('balance', 15, 2)->default(0);
            $table->timestamps();
            $table->unique(['team_id', 'currency'], 'credit_accounts_team_currency_uq');
        });

        Schema::create('credit_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('credit_account_id')->constrained('credit_accounts')->restrictOnDelete();
            $table->foreignId('billing_case_id')->nullable()->constrained('billing_cases')->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->string('kind', 16);
            $table->decimal('amount', 15, 2);
            $table->uuid('idempotency_key')->unique('credit_entries_idempotency_uq');
            $table->char('payload_hash', 64);
            $table->char('source_key', 64)->nullable()->unique('credit_entries_source_key_uq');
            $table->string('closure_key', 100)->nullable()->unique('credit_entries_closure_key_uq');
            $table->foreignId('reverses_entry_id')->nullable()->constrained('credit_entries')->restrictOnDelete();
            $table->unique('reverses_entry_id', 'credit_entries_reverses_uq');
            $table->text('reason');
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['credit_account_id', 'id'], 'credit_entries_account_id_idx');
        });

        Schema::create('financial_imports', function (Blueprint $table): void {
            $table->id();
            $table->char('sha256', 64);
            $table->string('sheet', 80);
            $table->string('mapping_version', 40);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 20);
            $table->foreignId('source_file_id')->constrained('storage_files')->restrictOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->unique(['sha256', 'sheet', 'mapping_version', 'actor_id'], 'financial_imports_identity_uq');
        });

        Schema::create('financial_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('financial_import_id')->constrained('financial_imports')->restrictOnDelete();
            $table->string('section', 16);
            $table->unsignedInteger('source_row');
            $table->json('raw');
            $table->json('normalized');
            $table->json('errors');
            $table->json('resolutions');
            $table->foreignId('target_case_id')->nullable()->constrained('billing_cases')->restrictOnDelete();
            $table->unsignedBigInteger('expected_version')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->unique(['financial_import_id', 'section', 'source_row'], 'financial_import_rows_identity_uq');
        });

        Schema::create('financial_import_row_cases', function (Blueprint $table): void {
            $table->foreignId('financial_import_row_id')->constrained('financial_import_rows')->restrictOnDelete();
            $table->foreignId('billing_case_id')->constrained('billing_cases')->restrictOnDelete();
            $table->unsignedBigInteger('expected_version');
            $table->primary(['financial_import_row_id', 'billing_case_id'], 'financial_import_row_cases_pk');
        });

        Schema::create('budget_version_actual_expense', function (Blueprint $table): void {
            $table->foreignId('budget_line_version_id')->constrained('budget_line_versions')->restrictOnDelete();
            $table->foreignId('actual_expense_id')->constrained('actual_expenses')->restrictOnDelete();
            $table->primary(['budget_line_version_id', 'actual_expense_id'], 'budget_version_actual_expense_pk');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Migración financiera sin reversión destructiva; restaurar copia verificada.');
    }
};
