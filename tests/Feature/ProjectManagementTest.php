<?php

namespace Tests\Feature;

use App\Models\Community;
use App\Models\Country;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_projects(): void
    {
        $this->get('/proyectos')
            ->assertRedirect('/login');
    }

    public function test_authenticated_users_can_view_projects(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/proyectos')
            ->assertOk();
    }

    public function test_authenticated_users_can_create_projects(): void
    {
        $user = User::factory()->create();
        [$country, $community] = $this->createCountryAndCommunity();

        $this->actingAs($user)
            ->post(route('projects.store'), [
                'country_id' => $country->id,
                'community_id' => $community->id,
                'code' => 'BOL-SR-001',
                'name' => 'Santa Rosa Water Project',
                'started_on' => '2026-07-01',
                'closed_on' => null,
                'description' => 'Proyecto base.',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('projects', [
            'country_id' => $country->id,
            'community_id' => $community->id,
            'code' => 'BOL-SR-001',
            'name' => 'Santa Rosa Water Project',
        ]);
    }

    public function test_project_code_must_be_unique(): void
    {
        $user = User::factory()->create();
        [$country] = $this->createCountryAndCommunity();
        Project::query()->create([
            'country_id' => $country->id,
            'community_id' => null,
            'code' => 'BOL-001',
            'name' => 'Existing Project',
            'started_on' => null,
            'closed_on' => null,
            'description' => null,
        ]);

        $this->actingAs($user)
            ->post(route('projects.store'), [
                'country_id' => $country->id,
                'community_id' => null,
                'code' => 'BOL-001',
                'name' => 'Duplicate Project',
                'started_on' => null,
                'closed_on' => null,
                'description' => null,
            ])
            ->assertSessionHasErrors('code');
    }

    public function test_project_rejects_community_from_a_different_country(): void
    {
        $user = User::factory()->create();
        [$firstCountry] = $this->createCountryAndCommunity('Bolivia', 'Santa Rosa');
        [, $secondCommunity] = $this->createCountryAndCommunity('Honduras', 'Cerritos');

        $this->actingAs($user)
            ->post(route('projects.store'), [
                'country_id' => $firstCountry->id,
                'community_id' => $secondCommunity->id,
                'code' => 'BOL-BAD-001',
                'name' => 'Invalid Community Project',
                'started_on' => null,
                'closed_on' => null,
                'description' => null,
            ])
            ->assertSessionHasErrors('community_id');
    }

    public function test_closed_date_must_not_be_before_started_date(): void
    {
        $user = User::factory()->create();
        [$country] = $this->createCountryAndCommunity();

        $this->actingAs($user)
            ->post(route('projects.store'), [
                'country_id' => $country->id,
                'community_id' => null,
                'code' => 'BOL-DATE-001',
                'name' => 'Invalid Dates Project',
                'started_on' => '2026-07-10',
                'closed_on' => '2026-07-01',
                'description' => null,
            ])
            ->assertSessionHasErrors('closed_on');
    }

    public function test_authenticated_users_can_update_projects(): void
    {
        $user = User::factory()->create();
        [$country, $community] = $this->createCountryAndCommunity();
        $project = Project::query()->create([
            'country_id' => $country->id,
            'community_id' => null,
            'code' => 'BOL-002',
            'name' => 'Original Project',
            'started_on' => null,
            'closed_on' => null,
            'description' => null,
        ]);

        $this->actingAs($user)
            ->patch(route('projects.update', $project->id), [
                'country_id' => $country->id,
                'community_id' => $community->id,
                'code' => 'BOL-002',
                'name' => 'Updated Project',
                'started_on' => '2026-08-01',
                'closed_on' => null,
                'description' => 'Actualizado.',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'community_id' => $community->id,
            'name' => 'Updated Project',
        ]);
    }

    public function test_authenticated_users_can_delete_unused_projects(): void
    {
        $user = User::factory()->create();
        [$country] = $this->createCountryAndCommunity();
        $project = Project::query()->create([
            'country_id' => $country->id,
            'community_id' => null,
            'code' => 'BOL-TMP-001',
            'name' => 'Temporary Project',
            'started_on' => null,
            'closed_on' => null,
            'description' => null,
        ]);

        $this->actingAs($user)
            ->delete(route('projects.destroy', $project->id))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseMissing('projects', [
            'id' => $project->id,
        ]);
    }

    /**
     * @return array{0: Country, 1: Community}
     */
    private function createCountryAndCommunity(string $countryName = 'Bolivia', string $communityName = 'Santa Rosa'): array
    {
        $country = Country::query()->create([
            'name' => $countryName,
            'description' => null,
        ]);

        $community = Community::query()->create([
            'country_id' => $country->id,
            'name' => $communityName,
            'description' => null,
        ]);

        return [$country, $community];
    }
}
