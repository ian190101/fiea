<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('system_settings', fn (Blueprint $table) => $table->json('liquid_glass')->nullable());
    }

    public function down(): void
    {
        Schema::table('system_settings', fn (Blueprint $table) => $table->dropColumn('liquid_glass'));
    }
};
