<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class UpdateUserScope
{
    /** @param array<int, int> $projectIds @param array<int, int> $chapterIds */
    public function execute(User $actor, User $target, array $projectIds, array $chapterIds, bool $global): void
    {
        abort_unless($actor->scope_all && Gate::forUser($actor)->allows('scope.manage'), 403);

        DB::transaction(function () use ($actor, $target, $projectIds, $chapterIds, $global): void {
            $continuity = app(AdministrativeContinuity::class);
            $continuity->lock();
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($actor->is_active && $actor->scope_all && Gate::forUser($actor)->allows('scope.manage'), 403);
            $locked = User::query()->lockForUpdate()->findOrFail($target->id);
            $continuity->ensure($locked, (bool) $locked->is_active, $global, $locked->roles()->pluck('roles.id')->all());
            $oldProjects = DB::table('user_project_assignments')->where('user_id', $locked->id)->pluck('project_id')->map(fn ($id) => (int) $id)->all();
            $oldChapters = DB::table('user_chapter_assignments')->where('user_id', $locked->id)->pluck('chapter_id')->map(fn ($id) => (int) $id)->all();

            if ($actor->is($locked)) {
                abort_if($global && ! $locked->scope_all, 403);
                abort_unless(array_diff($projectIds, $oldProjects) === [] && array_diff($chapterIds, $oldChapters) === [], 403);
            }

            DB::table('user_project_assignments')->where('user_id', $locked->id)->delete();
            DB::table('user_chapter_assignments')->where('user_id', $locked->id)->delete();
            $now = now();
            if ($projectIds !== []) {
                DB::table('user_project_assignments')->insert(array_map(fn (int $id) => [
                    'user_id' => $locked->id, 'project_id' => $id, 'created_at' => $now, 'updated_at' => $now,
                ], $projectIds));
            }
            if ($chapterIds !== []) {
                DB::table('user_chapter_assignments')->insert(array_map(fn (int $id) => [
                    'user_id' => $locked->id, 'chapter_id' => $id, 'created_at' => $now, 'updated_at' => $now,
                ], $chapterIds));
            }
            $locked->forceFill(['scope_all' => $global])->save();
            AuditLog::query()->create([
                'user_id' => $actor->id,
                'action' => 'user_scope_updated',
                'module' => 'superadmin',
                'auditable_type' => User::class,
                'auditable_id' => $locked->id,
                'ip_address' => request()->ip(),
                'user_agent' => substr((string) request()->userAgent(), 0, 500),
                'metadata' => [
                    'before' => ['scope_all' => (bool) $target->scope_all, 'project_ids' => $oldProjects, 'chapter_ids' => $oldChapters],
                    'after' => ['scope_all' => $global, 'project_ids' => $projectIds, 'chapter_ids' => $chapterIds],
                ],
            ]);
            if (config('session.driver') === 'database') {
                DB::table('sessions')->where('user_id', $locked->id)->delete();
            }
        });
    }
}
