<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\ExpenseCategory;
use App\Models\University;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_catalogs(): void
    {
        $this->get('/catalogos')
            ->assertRedirect('/login');
    }

    public function test_authenticated_users_can_view_catalogs(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/catalogos')
            ->assertOk();
    }

    public function test_authenticated_users_can_create_countries(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('catalogs.store', 'countries'), [
                'name' => 'Bolivia',
                'description' => 'Pais para proyectos FIEA.',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('countries', [
            'name' => 'Bolivia',
            'description' => 'Pais para proyectos FIEA.',
        ]);
    }

    public function test_authenticated_users_can_create_communities_for_a_country(): void
    {
        $user = User::factory()->create();
        $country = Country::query()->create([
            'name' => 'Bolivia',
            'description' => null,
        ]);

        $this->actingAs($user)
            ->post(route('catalogs.store', 'communities'), [
                'country_id' => $country->id,
                'name' => 'Santa Rosa',
                'description' => 'Comunidad del proyecto.',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('communities', [
            'country_id' => $country->id,
            'name' => 'Santa Rosa',
        ]);
    }

    public function test_authenticated_users_can_update_universities(): void
    {
        $user = User::factory()->create();
        $university = University::query()->create(['name' => 'Missouri ST']);

        $this->actingAs($user)
            ->patch(route('catalogs.update', ['universities', $university->id]), [
                'name' => 'Missouri S&T',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('universities', [
            'id' => $university->id,
            'name' => 'Missouri S&T',
        ]);
    }

    public function test_authenticated_users_can_create_expense_categories(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('catalogs.store', 'expense-categories'), [
                'name' => 'Lodging',
                'description' => 'Travel lodging expenses.',
                'fund_type' => 'DR',
                'applies_service_fee' => true,
                'applies_contingency' => false,
                'service_fee_percentage' => 5,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('expense_categories', [
            'name' => 'Lodging',
            'fund_type' => 'DR',
            'applies_service_fee' => true,
            'applies_contingency' => false,
        ]);
    }

    public function test_authenticated_users_can_delete_unused_universities(): void
    {
        $user = User::factory()->create();
        $university = University::query()->create(['name' => 'Temporary University']);

        $this->actingAs($user)
            ->delete(route('catalogs.destroy', ['universities', $university->id]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseMissing('universities', [
            'id' => $university->id,
        ]);
    }

    public function test_duplicate_expense_category_names_are_rejected(): void
    {
        $user = User::factory()->create();
        ExpenseCategory::query()->create([
            'name' => 'Meals',
            'description' => null,
            'fund_type' => 'DR',
            'applies_service_fee' => true,
            'applies_contingency' => true,
            'service_fee_percentage' => 0,
        ]);

        $this->actingAs($user)
            ->post(route('catalogs.store', 'expense-categories'), [
                'name' => 'Meals',
                'description' => null,
                'fund_type' => 'DR',
                'applies_service_fee' => true,
                'applies_contingency' => true,
                'service_fee_percentage' => 0,
            ])
            ->assertSessionHasErrors('name');
    }
}
