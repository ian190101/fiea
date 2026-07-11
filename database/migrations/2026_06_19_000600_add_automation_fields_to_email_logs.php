<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->string('source', 30)->default('manual')->after('status');
            $table->unsignedSmallInteger('retry_count')->default(0)->after('source');
            $table->timestamp('last_attempted_at')->nullable()->after('retry_count');
            $table->timestamp('next_retry_at')->nullable()->after('last_attempted_at');
            $table->index(['source', 'status', 'next_retry_at'], 'email_logs_automation_idx');
        });
    }

    public function down(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->dropIndex('email_logs_automation_idx');
            $table->dropColumn(['source', 'retry_count', 'last_attempted_at', 'next_retry_at']);
        });
    }
};
