<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_people', function (Blueprint $table) {
            $table->index(['full_name', 'email'], 'contact_people_name_email_idx');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->index(['country_id', 'community_id', 'name'], 'projects_location_name_idx');
            $table->index(['started_on', 'closed_on'], 'projects_dates_idx');
        });

        Schema::table('trip_phases', function (Blueprint $table) {
            $table->index(['starts_on', 'ends_on'], 'trip_phases_dates_idx');
            $table->index(['status', 'starts_on'], 'trip_phases_status_start_idx');
        });

        Schema::table('estimated_expenses', function (Blueprint $table) {
            $table->index(['trip_phase_id', 'fund_type', 'description'], 'estimated_phase_fund_desc_idx');
        });

        Schema::table('actual_expenses', function (Blueprint $table) {
            $table->index(['trip_phase_id', 'fund_type', 'reported_at'], 'actual_phase_fund_reported_idx');
            $table->index(['receipt_number', 'reported_at'], 'actual_receipt_reported_idx');
        });

        Schema::table('receipts', function (Blueprint $table) {
            $table->index(['actual_expense_id', 'issued_on'], 'receipts_expense_issued_idx');
            $table->index(['receipt_number', 'issued_on'], 'receipts_number_issued_idx');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->index(['accounting_status', 'created_at'], 'invoices_accounting_created_idx');
            $table->index(['status', 'created_at'], 'invoices_status_created_idx');
            $table->index(['contact_person_id', 'created_at'], 'invoices_contact_created_idx');
        });

        Schema::table('email_logs', function (Blueprint $table) {
            $table->index(['status', 'sent_at'], 'email_logs_status_sent_idx');
        });
    }

    public function down(): void
    {
        Schema::table('contact_people', function (Blueprint $table) {
            $table->dropIndex('contact_people_name_email_idx');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex('projects_location_name_idx');
            $table->dropIndex('projects_dates_idx');
        });

        Schema::table('trip_phases', function (Blueprint $table) {
            $table->dropIndex('trip_phases_dates_idx');
            $table->dropIndex('trip_phases_status_start_idx');
        });

        Schema::table('estimated_expenses', function (Blueprint $table) {
            $table->dropIndex('estimated_phase_fund_desc_idx');
        });

        Schema::table('actual_expenses', function (Blueprint $table) {
            $table->dropIndex('actual_phase_fund_reported_idx');
            $table->dropIndex('actual_receipt_reported_idx');
        });

        Schema::table('receipts', function (Blueprint $table) {
            $table->dropIndex('receipts_expense_issued_idx');
            $table->dropIndex('receipts_number_issued_idx');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_accounting_created_idx');
            $table->dropIndex('invoices_status_created_idx');
            $table->dropIndex('invoices_contact_created_idx');
        });

        Schema::table('email_logs', function (Blueprint $table) {
            $table->dropIndex('email_logs_status_sent_idx');
        });
    }
};
