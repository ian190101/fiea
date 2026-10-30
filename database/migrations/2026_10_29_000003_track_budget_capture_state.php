<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_line_versions', function (Blueprint $table): void {
            // Las versiones anteriores proceden de guardados; solo las nuevas plantillas se marcan pendientes.
            $table->boolean('is_captured')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('budget_line_versions', fn (Blueprint $table) => $table->dropColumn('is_captured'));
    }
};
