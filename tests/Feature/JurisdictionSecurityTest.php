<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\TripPhase;
use App\Models\User;
use App\Services\Security\ScopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class JurisdictionSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_permiso_sin_asignacion_no_abre_una_fase_ajena(): void
    {
        [$actor, $own, $foreign] = $this->fixture();
        $scope = app(ScopeService::class);

        self::assertTrue($scope->permits($actor, $own));
        self::assertFalse($scope->permits($actor, $foreign));
        self::assertSame([$own], $scope->tripPhases($actor)->orderBy('id')->pluck('id')->all());
        $this->actingAs($actor)->get(route('trip-phases.index'))->assertOk();

        $actor->forceFill(['is_active' => false])->save();
        self::assertFalse($scope->permits($actor->fresh(), $own));
    }

    public function test_scope_global_no_concede_permiso_rbac_y_un_rol_nuevo_no_omite_reglas(): void
    {
        [$actor, , $foreign] = $this->fixture();
        $actor->roles()->detach();
        $role = Role::query()->create(['code' => 'externo', 'name' => 'Externo']);
        $actor->roles()->attach($role);
        $actor->forceFill(['scope_all' => true])->save();

        self::assertTrue(app(ScopeService::class)->permits($actor, $foreign));
        self::assertFalse($actor->fresh()->hasPermission('trip_phases.view'));
        $this->actingAs($actor)->get(route('trip-phases.index'))->assertForbidden();
    }

    /** @return array{User, int, int} */
    private function fixture(): array
    {
        $actor = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $role = Role::query()->create(['code' => 'tecnico_prueba', 'name' => 'Técnico']);
        $permission = Permission::query()->create([
            'code' => 'trip_phases.view', 'module' => 'trip_phases', 'name' => 'Ver fases',
        ]);
        $role->permissions()->attach($permission);
        $actor->roles()->attach($role);
        $now = now();
        $country = DB::table('countries')->insertGetId(['name' => 'Bolivia', 'created_at' => $now, 'updated_at' => $now]);
        $chapterType = DB::table('chapter_types')->insertGetId(['name' => 'Universitario', 'created_at' => $now, 'updated_at' => $now]);
        $chapter = DB::table('chapters')->insertGetId([
            'chapter_type_id' => $chapterType, 'name' => 'Capítulo de prueba', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $team = DB::table('teams')->insertGetId([
            'chapter_id' => $chapter, 'name' => 'Equipo de prueba', 'credit_balance' => '0.00',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $phases = [];
        foreach (['PROPIO', 'AJENO'] as $code) {
            $project = DB::table('projects')->insertGetId([
                'country_id' => $country, 'code' => $code, 'name' => $code,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $phases[] = DB::table('trip_phases')->insertGetId([
                'project_id' => $project, 'team_id' => $team, 'phase' => 'Initial Visit',
                'starts_on' => '2026-11-01', 'ends_on' => '2026-11-02',
                'volunteer_count' => 1, 'staff_count' => 1, 'status' => 'draft',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        DB::table('user_project_assignments')->insert([
            'user_id' => $actor->id, 'project_id' => TripPhase::query()->findOrFail($phases[0])->project_id,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        return [$actor, $phases[0], $phases[1]];
    }
}
