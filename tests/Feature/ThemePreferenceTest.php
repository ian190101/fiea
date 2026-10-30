<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class ThemePreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_theme_can_be_changed_after_login_and_survives_navigation(): void
    {
        $user = User::factory()->create(['theme_preference' => 'light']);
        $this->post('/login', ['username' => $user->username, 'password' => 'password'])->assertRedirect();
        $this->patchJson(route('theme-preference.update'), ['theme_preference' => 'dark'])->assertNoContent();
        $this->assertSame('dark', $user->fresh()->theme_preference);
        $this->get(route('profile.edit'))->assertInertia(fn (Assert $page) => $page->where('auth.user.theme_preference', 'dark'));
        $this->patchJson(route('theme-preference.update'), ['theme_preference' => 'light'])->assertNoContent();
        $this->assertSame('light', $user->fresh()->theme_preference);
    }

    public function test_invalid_theme_does_not_change_the_saved_preference(): void
    {
        $user = User::factory()->create(['theme_preference' => 'dark']);
        $this->actingAs($user)->patchJson(route('theme-preference.update'), ['theme_preference' => 'invalid'])->assertUnprocessable();
        $this->assertSame('dark', $user->fresh()->theme_preference);
    }
}
