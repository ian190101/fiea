<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('invoice_type', 10);
            $table->string('stage', 20);
            $table->string('subject_template');
            $table->text('body_template');
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['invoice_type', 'stage'], 'email_templates_type_stage_unique');
            $table->index(['is_active', 'invoice_type', 'stage'], 'email_templates_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
