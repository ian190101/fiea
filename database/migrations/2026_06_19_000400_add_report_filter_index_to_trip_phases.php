<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_phases', function (Blueprint $table) {
            $table->index(['project_id', 'starts_on'], 'trip_phases_project_start_idx');
        });
    }

    public function down(): void
    {
        Schema::table('trip_phases', function (Blueprint $table) {
            $table->dropIndex('trip_phases_project_start_idx');
        });
    }
};
