<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('scope_all')->default(false);
        });
        Schema::create('user_project_assignments', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['user_id', 'project_id']);
            $table->index(['project_id', 'user_id']);
        });
        Schema::create('user_chapter_assignments', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chapter_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['user_id', 'chapter_id']);
            $table->index(['chapter_id', 'user_id']);
        });
        Schema::table('trip_phases', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('trip_phases', fn (Blueprint $t) => $t->dropColumn('version'));
        Schema::dropIfExists('user_chapter_assignments');
        Schema::dropIfExists('user_project_assignments');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('scope_all'));
    }
};
