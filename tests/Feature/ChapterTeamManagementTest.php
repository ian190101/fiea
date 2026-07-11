<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\ChapterType;
use App\Models\Team;
use App\Models\University;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChapterTeamManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_chapters(): void
    {
        $this->get('/capitulos')
            ->assertRedirect('/login');
    }

    public function test_authenticated_users_can_view_chapters(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/capitulos')
            ->assertOk();
    }

    public function test_authenticated_users_can_create_chapters_with_optional_university(): void
    {
        $user = User::factory()->create();
        $chapterType = ChapterType::query()->create([
            'name' => 'Universitario',
            'description' => null,
        ]);
        $university = University::query()->create(['name' => 'Missouri S&T']);

        $this->actingAs($user)
            ->post(route('chapters.store'), [
                'chapter_type_id' => $chapterType->id,
                'university_id' => $university->id,
                'name' => 'Missouri S&T Chapter',
                'description' => 'Capitulo universitario.',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('chapters', [
            'chapter_type_id' => $chapterType->id,
            'university_id' => $university->id,
            'name' => 'Missouri S&T Chapter',
        ]);
    }

    public function test_authenticated_users_can_create_professional_chapters_without_university(): void
    {
        $user = User::factory()->create();
        $chapterType = ChapterType::query()->create([
            'name' => 'Profesional',
            'description' => null,
        ]);

        $this->actingAs($user)
            ->post(route('chapters.store'), [
                'chapter_type_id' => $chapterType->id,
                'university_id' => null,
                'name' => 'Professional Chapter',
                'description' => null,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('chapters', [
            'chapter_type_id' => $chapterType->id,
            'university_id' => null,
            'name' => 'Professional Chapter',
        ]);
    }

    public function test_authenticated_users_can_create_teams_with_credit_balance(): void
    {
        $user = User::factory()->create();
        $chapter = $this->createChapter();

        $this->actingAs($user)
            ->post(route('teams.store'), [
                'chapter_id' => $chapter->id,
                'name' => 'Cerritos Team',
                'description' => 'Equipo para fase de viaje.',
                'credit_balance' => 1250.75,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('teams', [
            'chapter_id' => $chapter->id,
            'name' => 'Cerritos Team',
            'credit_balance' => 1250.75,
        ]);
    }

    public function test_authenticated_users_can_update_team_credit_balance(): void
    {
        $user = User::factory()->create();
        $chapter = $this->createChapter();
        $team = Team::query()->create([
            'chapter_id' => $chapter->id,
            'name' => 'Santa Rosa Team',
            'description' => null,
            'credit_balance' => 0,
        ]);

        $this->actingAs($user)
            ->patch(route('teams.update', $team->id), [
                'chapter_id' => $chapter->id,
                'name' => 'Santa Rosa Team',
                'description' => 'Credito inicial actualizado.',
                'credit_balance' => 500,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('teams', [
            'id' => $team->id,
            'credit_balance' => 500,
        ]);
    }

    public function test_team_names_must_be_unique_inside_the_same_chapter(): void
    {
        $user = User::factory()->create();
        $chapter = $this->createChapter();
        Team::query()->create([
            'chapter_id' => $chapter->id,
            'name' => 'Shared Team',
            'description' => null,
            'credit_balance' => 0,
        ]);

        $this->actingAs($user)
            ->post(route('teams.store'), [
                'chapter_id' => $chapter->id,
                'name' => 'Shared Team',
                'description' => null,
                'credit_balance' => 0,
            ])
            ->assertSessionHasErrors('name');
    }

    public function test_authenticated_users_can_delete_unused_teams(): void
    {
        $user = User::factory()->create();
        $chapter = $this->createChapter();
        $team = Team::query()->create([
            'chapter_id' => $chapter->id,
            'name' => 'Temporary Team',
            'description' => null,
            'credit_balance' => 0,
        ]);

        $this->actingAs($user)
            ->delete(route('teams.destroy', $team->id))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseMissing('teams', [
            'id' => $team->id,
        ]);
    }

    private function createChapter(): Chapter
    {
        $chapterType = ChapterType::query()->create([
            'name' => 'Universitario',
            'description' => null,
        ]);

        return Chapter::query()->create([
            'chapter_type_id' => $chapterType->id,
            'university_id' => null,
            'name' => 'Base Chapter',
            'description' => null,
        ]);
    }
}
