<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 80);
            $table->unsignedBigInteger('aggregate_id');
            $table->string('dedup_key', 191)->unique();
            $table->json('payload');
            $table->string('status', 16)->default('pending');
            $table->timestamp('available_at');
            $table->timestamp('locked_until')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->uuid('error_reference')->nullable();
            $table->timestamps();
            $table->index(['status', 'available_at', 'id'], 'outbox_ready_idx');
        });
        Schema::table('email_logs', function (Blueprint $table): void {
            $table->uuid('delivery_key')->nullable()->unique();
            $table->json('recipients_snapshot')->nullable();
            $table->json('attachment_snapshot')->nullable();
            $table->char('payload_hash', 64)->nullable();
            $table->string('provider_message_id')->nullable();
            $table->timestamp('dispatch_deadline')->nullable();
        });
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->foreignId('trip_phase_id')->nullable()->constrained('trip_phases')->restrictOnDelete();
            $table->uuid('correlation_id')->nullable();
            $table->index(['trip_phase_id', 'created_at', 'id'], 'audit_phase_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex('audit_phase_created_idx');
            $table->dropConstrainedForeignId('trip_phase_id');
            $table->dropColumn('correlation_id');
        });
        Schema::table('email_logs', function (Blueprint $table): void {
            $table->dropUnique(['delivery_key']);
            $table->dropColumn(['delivery_key', 'recipients_snapshot', 'attachment_snapshot',
                'payload_hash', 'provider_message_id', 'dispatch_deadline']);
        });
        Schema::dropIfExists('outbox_events');
    }
};
