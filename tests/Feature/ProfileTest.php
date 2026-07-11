<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create(['username' => 'verified_user']);

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Profile/Edit')
                ->loadDeferredProps('profile', fn (Assert $page) => $page
                    ->has('security')
                    ->has('activity')
                )
            );
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create(['username' => 'verified_user']);

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'username' => 'testuser',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('testuser', $user->username);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'username' => $user->username,
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_profile_rejects_username_with_spaces(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'username' => 'invalid username',
                'email' => $user->email,
            ])
            ->assertSessionHasErrors('username');
    }

    public function test_profile_activity_only_shows_current_user_logs(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        AuditLog::query()->create([
            'user_id' => $user->id,
            'action' => 'own_action',
            'module' => 'profile',
        ]);
        AuditLog::query()->create([
            'user_id' => $other->id,
            'action' => 'other_action',
            'module' => 'profile',
        ]);

        $this
            ->actingAs($user)
            ->get('/profile')
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps('profile', fn (Assert $page) => $page
                    ->where('activity.0.action', 'own_action')
                    ->missing('activity.1')
                )
            );
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }
}
