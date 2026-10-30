<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Models\Community;
use App\Models\Project;
use App\Models\User;
use App\Services\Security\AuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SaveProject
{
    public function __construct(private readonly AuditWriter $audit) {}

    public function execute(User $actor, ?Project $project, array $data): Project
    {
        return DB::transaction(function () use ($actor, $project, $data): Project {
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($actor->hasPermission('projects.manage'), 403);
            if ($project) {
                $project = Project::query()->lockForUpdate()->findOrFail($project->id);
                abort_unless($actor->scope_all || DB::table('user_project_assignments')
                    ->where('user_id', $actor->id)->where('project_id', $project->id)->exists(), 404);
            } else {
                abort_unless($actor->scope_all, 403);
            }
            if (isset($data['community_id'])) {
                $community = Community::query()->findOrFail($data['community_id']);
                abort_unless((int) $community->country_id === (int) $data['country_id'], 422);
            }
            $project ??= new Project;
            $before = $project->only(['code', 'name']);
            $project->fill($data)->save();
            $this->audit->record($actor, 'project_saved', $project, null, $before,
                $project->only(['code', 'name']), (string) (request()->attributes->get('correlation_id') ?? Str::uuid()));

            return $project;
        }, 3);
    }
}
