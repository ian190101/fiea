<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\ChapterType;
use App\Models\ContactAssignment;
use App\Models\ContactPerson;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_contacts(): void
    {
        $this->get('/contactos')
            ->assertRedirect('/login');
    }

    public function test_authenticated_users_can_view_contacts(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/contactos')
            ->assertOk();
    }

    public function test_authenticated_users_can_create_contacts(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('contacts.store'), [
                'full_name' => 'Jane Doe',
                'email' => 'jane@example.com',
                'phone' => '+1 555 0101',
                'physical_address' => '123 Main St',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('contact_people', [
            'full_name' => 'Jane Doe',
            'email' => 'jane@example.com',
        ]);
    }

    public function test_contact_emails_must_be_unique_when_present(): void
    {
        $user = User::factory()->create();
        ContactPerson::query()->create([
            'full_name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone' => null,
            'physical_address' => null,
        ]);

        $this->actingAs($user)
            ->post(route('contacts.store'), [
                'full_name' => 'Jane Smith',
                'email' => 'jane@example.com',
                'phone' => null,
                'physical_address' => null,
            ])
            ->assertSessionHasErrors('email');
    }

    public function test_authenticated_users_can_create_contact_assignments_for_teams(): void
    {
        $user = User::factory()->create();
        $contact = $this->createContact();
        [$chapter, $team] = $this->createChapterAndTeam();

        $this->actingAs($user)
            ->post(route('contact-assignments.store'), [
                'contact_person_id' => $contact->id,
                'chapter_id' => $chapter->id,
                'team_id' => $team->id,
                'role' => 'Travel Lead',
                'is_billing_contact' => false,
                'is_email_recipient' => true,
                'is_active' => true,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('contact_assignments', [
            'contact_person_id' => $contact->id,
            'chapter_id' => $chapter->id,
            'team_id' => $team->id,
            'role' => 'Travel Lead',
            'is_email_recipient' => true,
            'is_active' => true,
        ]);
    }

    public function test_assignment_derives_chapter_when_only_team_is_selected(): void
    {
        $user = User::factory()->create();
        $contact = $this->createContact();
        [$chapter, $team] = $this->createChapterAndTeam();

        $this->actingAs($user)
            ->post(route('contact-assignments.store'), [
                'contact_person_id' => $contact->id,
                'chapter_id' => null,
                'team_id' => $team->id,
                'role' => 'Primary Contact',
                'is_billing_contact' => true,
                'is_email_recipient' => true,
                'is_active' => true,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('contact_assignments', [
            'contact_person_id' => $contact->id,
            'chapter_id' => $chapter->id,
            'team_id' => $team->id,
        ]);
    }

    public function test_assignment_rejects_team_from_a_different_chapter(): void
    {
        $user = User::factory()->create();
        $contact = $this->createContact();
        [$firstChapter] = $this->createChapterAndTeam('First Chapter', 'First Team');
        [, $secondTeam] = $this->createChapterAndTeam('Second Chapter', 'Second Team');

        $this->actingAs($user)
            ->post(route('contact-assignments.store'), [
                'contact_person_id' => $contact->id,
                'chapter_id' => $firstChapter->id,
                'team_id' => $secondTeam->id,
                'role' => 'Primary Contact',
                'is_billing_contact' => false,
                'is_email_recipient' => true,
                'is_active' => true,
            ])
            ->assertSessionHasErrors('team_id');
    }

    public function test_authenticated_users_can_update_assignment_email_recipient_flag(): void
    {
        $user = User::factory()->create();
        $contact = $this->createContact();
        [$chapter, $team] = $this->createChapterAndTeam();
        $assignment = ContactAssignment::query()->create([
            'contact_person_id' => $contact->id,
            'chapter_id' => $chapter->id,
            'team_id' => $team->id,
            'role' => 'Volunteer',
            'is_billing_contact' => false,
            'is_email_recipient' => false,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->patch(route('contact-assignments.update', $assignment->id), [
                'contact_person_id' => $contact->id,
                'chapter_id' => $chapter->id,
                'team_id' => $team->id,
                'role' => 'Volunteer',
                'is_billing_contact' => false,
                'is_email_recipient' => true,
                'is_active' => true,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('contact_assignments', [
            'id' => $assignment->id,
            'is_email_recipient' => true,
        ]);
    }

    public function test_authenticated_users_can_delete_assignments(): void
    {
        $user = User::factory()->create();
        $contact = $this->createContact();
        [$chapter, $team] = $this->createChapterAndTeam();
        $assignment = ContactAssignment::query()->create([
            'contact_person_id' => $contact->id,
            'chapter_id' => $chapter->id,
            'team_id' => $team->id,
            'role' => 'Volunteer',
            'is_billing_contact' => false,
            'is_email_recipient' => true,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->delete(route('contact-assignments.destroy', $assignment->id))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseMissing('contact_assignments', [
            'id' => $assignment->id,
        ]);
    }

    private function createContact(): ContactPerson
    {
        return ContactPerson::query()->create([
            'full_name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone' => null,
            'physical_address' => null,
        ]);
    }

    /**
     * @return array{0: Chapter, 1: Team}
     */
    private function createChapterAndTeam(string $chapterName = 'Base Chapter', string $teamName = 'Base Team'): array
    {
        $chapterType = ChapterType::query()->firstOrCreate([
            'name' => $chapterName.' Type',
        ], [
            'description' => null,
        ]);

        $chapter = Chapter::query()->create([
            'chapter_type_id' => $chapterType->id,
            'university_id' => null,
            'name' => $chapterName,
            'description' => null,
        ]);

        $team = Team::query()->create([
            'chapter_id' => $chapter->id,
            'name' => $teamName,
            'description' => null,
            'credit_balance' => 0,
        ]);

        return [$chapter, $team];
    }
}
