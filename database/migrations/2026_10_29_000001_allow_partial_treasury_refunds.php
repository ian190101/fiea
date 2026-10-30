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
        Schema::table('treasury_payments', function (Blueprint $table): void {
            // El bloqueo del cobro original y la suma de devoluciones garantizan el límite acumulado.
            $table->index('reverses_payment_id', 'treasury_payments_reverses_idx');
            $table->dropUnique('treasury_payments_reverses_uq');
        });
    }

    public function down(): void
    {
        if (DB::table('treasury_payments')->whereNotNull('reverses_payment_id')
            ->groupBy('reverses_payment_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('No se puede restaurar la restricción anterior mientras existan devoluciones parciales múltiples.');
        }
        Schema::table('treasury_payments', function (Blueprint $table): void {
            $table->unique('reverses_payment_id', 'treasury_payments_reverses_uq');
            $table->dropIndex('treasury_payments_reverses_idx');
        });
    }
};
