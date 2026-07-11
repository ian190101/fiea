<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_files', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->default('cloudflare_r2');
            $table->string('bucket')->nullable();
            $table->string('object_key')->unique();
            $table->string('original_name');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('checksum')->nullable();
            $table->string('public_url')->nullable();
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('logo_file_id')->nullable()->constrained('storage_files')->nullOnDelete();
            $table->string('primary_color', 7)->default('#2563eb');
            $table->string('secondary_color', 7)->default('#0f766e');
            $table->string('accent_color', 7)->default('#f59e0b');
            $table->boolean('lock_final_invoice_by_default')->default(true);
            $table->boolean('accounting_can_edit_summary')->default(false);
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('module');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id']);
        });

        Schema::create('role_user', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'user_id']);
        });

        Schema::create('chapter_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('universities', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('communities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->timestamps();
            $table->unique(['country_id', 'name']);
        });

        Schema::create('chapters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chapter_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('university_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chapter_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->decimal('credit_balance', 12, 2)->default(0);
            $table->timestamps();
            $table->unique(['chapter_id', 'name']);
        });

        Schema::create('contact_people', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('physical_address')->nullable();
            $table->timestamps();
        });

        Schema::create('contact_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_person_id')->constrained('contact_people')->cascadeOnDelete();
            $table->foreignId('chapter_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->boolean('is_billing_contact')->default(false);
            $table->boolean('is_email_recipient')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['chapter_id', 'team_id', 'role']);
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained()->restrictOnDelete();
            $table->foreignId('community_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code')->unique();
            $table->string('name');
            $table->date('started_on')->nullable();
            $table->date('closed_on')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('trip_phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('team_id')->constrained()->restrictOnDelete();
            $table->foreignId('assigned_technician_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('phase', 40);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedSmallInteger('volunteer_count')->default(0);
            $table->unsignedSmallInteger('staff_count')->default(0);
            $table->string('status', 40)->default('draft');
            $table->foreignId('draft_pdf_file_id')->nullable()->constrained('storage_files')->nullOnDelete();
            $table->timestamps();
            $table->index(['project_id', 'team_id', 'status']);
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->string('fund_type', 20)->default('DR');
            $table->boolean('applies_service_fee')->default(false);
            $table->boolean('applies_contingency')->default(false);
            $table->decimal('service_fee_percentage', 5, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('estimated_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_phase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->string('description');
            $table->string('unit', 80)->nullable();
            $table->decimal('initial_unit_cost', 12, 2)->default(0);
            $table->decimal('initial_quantity', 12, 2)->default(0);
            $table->decimal('estimated_total', 12, 2)->default(0);
            $table->string('fund_type', 20);
            $table->timestamps();
        });

        Schema::create('actual_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_phase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('estimated_expense_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('reported_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('description');
            $table->string('unit', 80)->nullable();
            $table->decimal('final_unit_cost', 12, 2)->default(0);
            $table->decimal('final_quantity', 12, 2)->default(0);
            $table->decimal('real_total', 12, 2)->default(0);
            $table->string('receipt_number')->nullable();
            $table->string('fund_type', 20);
            $table->timestamp('reported_at')->nullable();
            $table->timestamps();
        });

        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actual_expense_id')->constrained()->cascadeOnDelete();
            $table->foreignId('storage_file_id')->constrained('storage_files')->restrictOnDelete();
            $table->string('receipt_number')->nullable();
            $table->date('issued_on')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_phase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_person_id')->nullable()->constrained('contact_people')->nullOnDelete();
            $table->foreignId('pdf_file_id')->nullable()->constrained('storage_files')->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('code')->unique();
            $table->string('type', 20);
            $table->string('stage', 20);
            $table->string('status', 40)->default('draft');
            $table->decimal('total_dr', 12, 2)->default(0);
            $table->decimal('total_wodr', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2)->default(0);
            $table->decimal('balance_conciliation', 12, 2)->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();
            $table->index(['trip_phase_id', 'type', 'stage', 'status']);
        });

        Schema::create('invoice_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_person_id')->nullable()->constrained('contact_people')->nullOnDelete();
            $table->string('email');
            $table->string('recipient_type', 10)->default('to');
            $table->timestamps();
        });

        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject');
            $table->text('body')->nullable();
            $table->string('status', 40)->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('module');
            $table->nullableMorphs('auditable');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['module', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('email_logs');
        Schema::dropIfExists('invoice_recipients');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('receipts');
        Schema::dropIfExists('actual_expenses');
        Schema::dropIfExists('estimated_expenses');
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('trip_phases');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('contact_assignments');
        Schema::dropIfExists('contact_people');
        Schema::dropIfExists('teams');
        Schema::dropIfExists('chapters');
        Schema::dropIfExists('communities');
        Schema::dropIfExists('countries');
        Schema::dropIfExists('universities');
        Schema::dropIfExists('chapter_types');
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('storage_files');
    }
};
