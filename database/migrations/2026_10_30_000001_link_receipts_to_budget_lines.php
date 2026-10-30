<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table): void {
            $table->unsignedBigInteger('actual_expense_id')->nullable()->change();
            $table->foreignId('budget_line_id')->nullable()->constrained('budget_lines')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('receipts')->whereNotNull('budget_line_id')->exists()) {
            throw new RuntimeException('Hay comprobantes de matriz; conserva su vínculo antes de revertir.');
        }
        Schema::table('receipts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('budget_line_id');
            $table->unsignedBigInteger('actual_expense_id')->nullable(false)->change();
        });
    }
};
