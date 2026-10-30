<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $seen = [];
        DB::table('users')->orderBy('id')->chunkById(500, function ($users) use (&$seen): void {
            foreach ($users as $user) {
                $key = Str::lower(trim($user->username));
                if (isset($seen[$key])) {
                    throw new RuntimeException('Hay identificadores de usuario que coinciden al normalizar. Resuelve la colisión antes de migrar.');
                }
                $seen[$key] = true;
            }
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->string('username_normalized', 255)->nullable()->unique();
        });
        DB::table('users')->orderBy('id')->chunkById(500, function ($users): void {
            foreach ($users as $user) {
                DB::table('users')->where('id', $user->id)
                    ->update(['username_normalized' => Str::lower(trim($user->username))]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('username_normalized');
        });
    }
};
