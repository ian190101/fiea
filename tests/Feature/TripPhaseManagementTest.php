<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\ChapterType;
use App\Models\Community;
use App\Models\Country;
use App\Models\Project;
use App\Models\Role;
use App\Models\Team;
use App\Models\TripPhase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TripPhaseManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_trip_phases(): void
    {
        $this->get('/viajes')
            ->assertRedirect('/login');
    }

    public function test_authenticated_users_can_view_trip_phases(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/viajes')
            ->assertOk();
    }

    public function test_authenticated_users_can_create_trip_phases(): void
    {
        $user = User::factory()->create();
        $technician = $this->createTechnician();
        [$project, $team] = $this->createProjectAndTeam();

        $this->actingAs($user)
            ->post(route('trip-phases.store'), [
                'project_id' => $project->id,
                'team_id' => $team->id,
                'assigned_technician_id' => $technician->id,
                'phase' => 'Initial Visit',
                'starts_on' => '2026-07-01',
                'ends_on' => '2026-07-10',
                'volunteer_count' => 12,
                'staff_count' => 2,
                'status' => 'draft',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('trip_phases', [
            'project_id' => $project->id,
            'team_id' => $team->id,
            'assigned_technician_id' => $technician->id,
            'phase' => 'Initial Visit',
            'volunteer_count' => 12,
            'staff_count' => 2,
            'status' => 'draft',
        ]);
    }

    public function test_trip_phase_can_be_created_without_assigned_technician(): void
    {
        $user = User::factory()->create();
        [$project, $team] = $this->createProjectAndTeam();

        $this->actingAs($user)
            ->post(route('trip-phases.store'), [
                'project_id' => $project->id,
                'team_id' => $team->id,
                'assigned_technician_id' => null,
                'phase' => 'Implementation Trip',
                'starts_on' => '2026-08-01',
                'ends_on' => '2026-08-05',
                'volunteer_count' => 5,
                'staff_count' => 1,
                'status' => 'scheduled',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('trip_phases', [
            'project_id' => $project->id,
            'team_id' => $team->id,
            'assigned_technician_id' => null,
            'phase' => 'Implementation Trip',
        ]);
    }

    public function test_trip_phase_rejects_end_date_before_start_date(): void
    {
        $user = User::factory()->create();
        [$project, $team] = $this->createProjectAndTeam();

        $this->actingAs($user)
            ->post(route('trip-phases.store'), [
                'project_id' => $project->id,
                'team_id' => $team->id,
                'assigned_technician_id' => null,
                'phase' => 'Initial Visit',
                'starts_on' => '2026-07-10',
                'ends_on' => '2026-07-01',
                'volunteer_count' => 0,
                'staff_count' => 0,
                'status' => 'draft',
            ])
            ->assertSessionHasErrors('ends_on');
    }

    public function test_trip_phase_rejects_invalid_status(): void
    {
        $user = User::factory()->create();
        [$project, $team] = $this->createProjectAndTeam();

        $this->actingAs($user)
            ->post(route('trip-phases.store'), [
                'project_id' => $project->id,
                'team_id' => $team->id,
                'assigned_technician_id' => null,
                'phase' => 'Initial Visit',
                'starts_on' => '2026-07-01',
                'ends_on' => '2026-07-10',
                'volunteer_count' => 0,
                'staff_count' => 0,
                'status' => 'invalid',
            ])
            ->assertSessionHasErrors('status');
    }

    public function test_trip_phase_rejects_negative_counts(): void
    {
        $user = User::factory()->create();
        [$project, $team] = $this->createProjectAndTeam();

        $this->actingAs($user)
            ->post(route('trip-phases.store'), [
                'project_id' => $project->id,
                'team_id' => $team->id,
                'assigned_technician_id' => null,
                'phase' => 'Initial Visit',
                'starts_on' => '2026-07-01',
                'ends_on' => '2026-07-10',
                'volunteer_count' => -1,
                'staff_count' => 0,
                'status' => 'draft',
            ])
            ->assertSessionHasErrors('volunteer_count');
    }

    public function test_authenticated_users_can_update_trip_phases(): void
    {
        $user = User::factory()->create();
        [$project, $team] = $this->createProjectAndTeam();
        $tripPhase = TripPhase::query()->create([
            'project_id' => $project->id,
            'team_id' => $team->id,
            'assigned_technician_id' => null,
            'phase' => 'Initial Visit',
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-10',
            'volunteer_count' => 5,
            'staff_count' => 1,
            'status' => 'draft',
            'draft_pdf_file_id' => null,
        ]);

        $this->actingAs($user)
            ->patch(route('trip-phases.update', $tripPhase->id), [
                'project_id' => $project->id,
                'team_id' => $team->id,
                'assigned_technician_id' => null,
                'phase' => 'Follow-up Trip',
                'starts_on' => '2026-09-01',
                'ends_on' => '2026-09-04',
                'volunteer_count' => 8,
                'staff_count' => 2,
                'status' => 'scheduled',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('trip_phases', [
            'id' => $tripPhase->id,
            'phase' => 'Follow-up Trip',
            'volunteer_count' => 8,
            'status' => 'scheduled',
        ]);
    }

    public function test_authenticated_users_can_delete_unused_trip_phases(): void
    {
        $user = User::factory()->create();
        [$project, $team] = $this->createProjectAndTeam();
        $tripPhase = TripPhase::query()->create([
            'project_id' => $project->id,
            'team_id' => $team->id,
            'assigned_technician_id' => null,
            'phase' => 'Initial Visit',
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-10',
            'volunteer_count' => 5,
            'staff_count' => 1,
            'status' => 'draft',
            'draft_pdf_file_id' => null,
        ]);

        $this->actingAs($user)
            ->delete(route('trip-phases.destroy', $tripPhase->id))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseMissing('trip_phases', [
            'id' => $tripPhase->id,
        ]);
    }

    private function createTechnician(): User
    {
        $role = Role::query()->create([
            'code' => 'tecnico',
            'name' => 'Tecnico',
            'description' => null,
        ]);
        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);

        return $user;
    }

    /**
     * @return array{0: Project, 1: Team}
     */
    private function createProjectAndTeam(): array
    {
        $country = Country::query()->create([
            'name' => 'Bolivia',
            'description' => null,
        ]);
        $community = Community::query()->create([
            'country_id' => $country->id,
            'name' => 'Santa Rosa',
            'description' => null,
        ]);
        $project = Project::query()->create([
            'country_id' => $country->id,
            'community_id' => $community->id,
            'code' => 'BOL-SR-001',
            'name' => 'Santa Rosa Water Project',
            'started_on' => null,
            'closed_on' => null,
            'description' => null,
        ]);

        $chapterType = ChapterType::query()->create([
            'name' => 'Universitario',
            'description' => null,
        ]);
        $chapter = Chapter::query()->create([
            'chapter_type_id' => $chapterType->id,
            'university_id' => null,
            'name' => 'Missouri Chapter',
            'description' => null,
        ]);
        $team = Team::query()->create([
            'chapter_id' => $chapter->id,
            'name' => 'Missouri Team',
            'description' => null,
            'credit_balance' => 0,
        ]);

        return [$project, $team];
    }
}
