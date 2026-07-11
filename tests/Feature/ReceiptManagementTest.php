<?php

namespace Tests\Feature;

use App\Models\ActualExpense;
use App\Models\Chapter;
use App\Models\ChapterType;
use App\Models\Community;
use App\Models\Country;
use App\Models\ExpenseCategory;
use App\Models\Project;
use App\Models\Receipt;
use App\Models\StorageFile;
use App\Models\Team;
use App\Models\TripPhase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReceiptManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_receipts(): void
    {
        $this->get('/recibos')
            ->assertRedirect('/login');
    }

    public function test_authenticated_users_can_view_receipts(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/recibos')
            ->assertOk();
    }

    public function test_authenticated_users_can_upload_receipts(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $actualExpense = $this->createActualExpense($user);
        $file = UploadedFile::fake()->create('receipt.pdf', 12, 'application/pdf');

        $this->actingAs($user)
            ->post(route('receipts.store'), [
                'actual_expense_id' => $actualExpense->id,
                'receipt_number' => 'RCPT-001',
                'issued_on' => '2026-07-12',
                'amount' => 520,
                'file' => $file,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $receipt = Receipt::query()->firstOrFail();
        $storageFile = StorageFile::query()->findOrFail($receipt->storage_file_id);

        $this->assertSame($actualExpense->id, $receipt->actual_expense_id);
        $this->assertSame('RCPT-001', $receipt->receipt_number);
        $this->assertSame('receipt.pdf', $storageFile->original_name);
        $this->assertSame('local', $storageFile->provider);
        Storage::disk('local')->assertExists($storageFile->object_key);
    }

    public function test_receipt_upload_rejects_unsupported_file_type(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $actualExpense = $this->createActualExpense($user);
        $file = UploadedFile::fake()->create('receipt.exe', 10, 'application/octet-stream');

        $this->actingAs($user)
            ->post(route('receipts.store'), [
                'actual_expense_id' => $actualExpense->id,
                'receipt_number' => null,
                'issued_on' => null,
                'amount' => 10,
                'file' => $file,
            ])
            ->assertSessionHasErrors('file');
    }

    public function test_authenticated_users_can_update_receipt_metadata(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $receipt = $this->createReceipt($user);

        $this->actingAs($user)
            ->patch(route('receipts.update', $receipt->id), [
                'receipt_number' => 'RCPT-UPDATED',
                'issued_on' => '2026-07-20',
                'amount' => 600,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('receipts', [
            'id' => $receipt->id,
            'receipt_number' => 'RCPT-UPDATED',
            'amount' => 600,
        ]);
    }

    public function test_authenticated_users_can_download_receipts(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $receipt = $this->createReceipt($user);

        $this->actingAs($user)
            ->get(route('receipts.show', $receipt->id))
            ->assertOk();
    }

    public function test_authenticated_users_can_delete_receipts_and_storage_file(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $receipt = $this->createReceipt($user);
        $objectKey = $receipt->storageFile->object_key;
        $storageFileId = $receipt->storage_file_id;

        $this->actingAs($user)
            ->delete(route('receipts.destroy', $receipt->id))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseMissing('receipts', ['id' => $receipt->id]);
        $this->assertDatabaseMissing('storage_files', ['id' => $storageFileId]);
        Storage::disk('local')->assertMissing($objectKey);
    }

    private function createReceipt(User $user): Receipt
    {
        $actualExpense = $this->createActualExpense($user);
        Storage::disk('local')->put('receipts/test/receipt.pdf', '%PDF test');
        $storageFile = StorageFile::query()->create([
            'provider' => 'local',
            'bucket' => null,
            'object_key' => 'receipts/test/receipt.pdf',
            'original_name' => 'receipt.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 9,
            'checksum' => hash('sha256', '%PDF test'),
            'public_url' => null,
            'uploaded_by_id' => $user->id,
        ]);

        return Receipt::query()->create([
            'actual_expense_id' => $actualExpense->id,
            'storage_file_id' => $storageFile->id,
            'receipt_number' => 'RCPT-001',
            'issued_on' => '2026-07-12',
            'amount' => 520,
        ])->load('storageFile');
    }

    private function createActualExpense(User $user): ActualExpense
    {
        $country = Country::query()->create(['name' => 'Bolivia', 'description' => null]);
        $community = Community::query()->create(['country_id' => $country->id, 'name' => 'Santa Rosa', 'description' => null]);
        $project = Project::query()->create([
            'country_id' => $country->id,
            'community_id' => $community->id,
            'code' => 'BOL-SR-001',
            'name' => 'Santa Rosa Water Project',
            'started_on' => null,
            'closed_on' => null,
            'description' => null,
        ]);
        $chapterType = ChapterType::query()->create(['name' => 'Universitario', 'description' => null]);
        $chapter = Chapter::query()->create(['chapter_type_id' => $chapterType->id, 'university_id' => null, 'name' => 'Missouri Chapter', 'description' => null]);
        $team = Team::query()->create(['chapter_id' => $chapter->id, 'name' => 'Missouri Team', 'description' => null, 'credit_balance' => 0]);
        $tripPhase = TripPhase::query()->create([
            'project_id' => $project->id,
            'team_id' => $team->id,
            'assigned_technician_id' => null,
            'phase' => 'Initial Visit',
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-10',
            'volunteer_count' => 10,
            'staff_count' => 2,
            'status' => 'draft',
            'draft_pdf_file_id' => null,
        ]);
        $category = ExpenseCategory::query()->create([
            'name' => 'Lodging',
            'description' => null,
            'fund_type' => 'DR',
            'applies_service_fee' => false,
            'applies_contingency' => false,
            'service_fee_percentage' => 0,
        ]);

        return ActualExpense::query()->create([
            'trip_phase_id' => $tripPhase->id,
            'estimated_expense_id' => null,
            'expense_category_id' => $category->id,
            'reported_by_id' => $user->id,
            'description' => 'Hotel rooms final',
            'unit' => 'night',
            'final_unit_cost' => 130,
            'final_quantity' => 4,
            'real_total' => 520,
            'receipt_number' => 'RCPT-001',
            'fund_type' => 'DR',
            'reported_at' => now(),
        ]);
    }
}
