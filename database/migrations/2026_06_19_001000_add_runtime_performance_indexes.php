<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('role_user', function (Blueprint $table) {
            $table->index(['user_id', 'role_id'], 'role_user_user_role_idx');
        });

        Schema::table('permission_role', function (Blueprint $table) {
            $table->index(['role_id', 'permission_id'], 'permission_role_role_permission_idx');
        });

        Schema::table('email_logs', function (Blueprint $table) {
            $table->index(['invoice_id', 'status', 'created_at'], 'email_logs_invoice_status_created_idx');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(['user_id', 'created_at'], 'audit_logs_user_created_idx');
            $table->index(['module', 'created_at'], 'audit_logs_module_created_idx');
        });

        Schema::table('actual_expenses', function (Blueprint $table) {
            $table->index(['reported_by_id', 'reported_at'], 'actual_reporter_reported_idx');
            $table->index(['estimated_expense_id', 'trip_phase_id'], 'actual_estimated_phase_idx');
        });
    }

    public function down(): void
    {
        Schema::table('actual_expenses', function (Blueprint $table) {
            $table->dropIndex('actual_reporter_reported_idx');
            $table->dropIndex('actual_estimated_phase_idx');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_user_created_idx');
            $table->dropIndex('audit_logs_module_created_idx');
        });

        Schema::table('email_logs', function (Blueprint $table) {
            $table->dropIndex('email_logs_invoice_status_created_idx');
        });

        Schema::table('permission_role', function (Blueprint $table) {
            $table->dropIndex('permission_role_role_permission_idx');
        });

        Schema::table('role_user', function (Blueprint $table) {
            $table->dropIndex('role_user_user_role_idx');
        });
    }
};
