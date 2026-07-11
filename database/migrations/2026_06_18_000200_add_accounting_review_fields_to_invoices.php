<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('accounting_status', 40)->default('pending')->after('status');
            $table->text('accounting_note')->nullable()->after('balance_conciliation');
            $table->foreignId('accounting_reviewed_by_id')->nullable()->after('accounting_note')->constrained('users')->nullOnDelete();
            $table->timestamp('accounting_reviewed_at')->nullable()->after('accounting_reviewed_by_id');
            $table->index(['accounting_status', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['accounting_status', 'status']);
            $table->dropConstrainedForeignId('accounting_reviewed_by_id');
            $table->dropColumn(['accounting_status', 'accounting_note', 'accounting_reviewed_at']);
        });
    }
};
