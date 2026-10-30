<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Data\Financial\CreateInvoiceData;
use App\Http\Requests\InvoiceVersionRequest;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\VoidInvoiceRequest;
use App\Models\BillingCase;
use App\Models\BudgetLine;
use App\Models\BudgetLineVersion;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Finance\InvoiceLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

final class InvoiceLifecycleSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_solicitud_de_emision_rechaza_montos_y_estados_cliente(): void
    {
        $request = new StoreInvoiceRequest();
        $data = [
            'billing_case_id' => 1, 'stage' => 'initial', 'version' => 1,
            'idempotency_key' => '123e4567-e89b-12d3-a456-426614174000',
            'status' => 'paid', 'grand_total' => '0.01',
        ];
        $errors = Validator::make($data, $request->rules())->errors();
        self::assertTrue($errors->has('status'));
        self::assertTrue($errors->has('grand_total'));
    }

    public function test_version_y_anulacion_requieren_motivo(): void
    {
        self::assertTrue(Validator::make(['version' => 1, 'status' => 'approved'],
            (new InvoiceVersionRequest())->rules())->fails());
        self::assertTrue(Validator::make(['version' => 1, 'reason' => 'corto'],
            (new VoidInvoiceRequest())->rules())->fails());
        self::assertTrue(Validator::make(['version' => 1, 'reason' => 'Motivo documentado'],
            (new VoidInvoiceRequest())->rules())->passes());
    }

    public function test_emision_idempotente_y_aprobacion_segregada(): void
    {
        [$actor, $case, $contactId] = $this->financialCase();
        $service = app(InvoiceLifecycleService::class);
        $data = new CreateInvoiceData($case->id, 'initial', 1,
            '123e4567-e89b-12d3-a456-426614174001', $contactId);
        $invoice = $service->create($actor, $data);
        self::assertSame('149.00', $invoice->grand_total);
        self::assertSame($invoice->id, $service->create($actor, $data)->id);
        try {
            $service->create($actor, new CreateInvoiceData($case->id, 'final', 1,
                $data->idempotencyKey, $contactId));
            self::fail('El UUID no puede reutilizarse con otro payload.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            self::assertSame(409, $exception->getStatusCode());
        }

        try {
            $service->approve($actor, $invoice->id, 1);
            self::fail('El creador no debe autoaprobar.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            self::assertSame('draft', $invoice->fresh()->status);
        }

        $approver = User::query()->create([
            'name' => 'Revisor', 'username' => 'revisor', 'password' => 'ClaveTemporal123!',
            'is_active' => true, 'must_change_password' => false,
        ]);
        $approver->forceFill(['scope_all' => true])->save();
        $approver->roles()->sync($actor->roles()->pluck('roles.id'));
        BudgetLineVersion::query()->firstOrFail()->cells()->firstOrFail()
            ->update(['quantity' => '2.0000']);
        try {
            $service->approve($approver, $invoice->id, 1);
            self::fail('El borrador obsoleto no debe aprobarse.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            self::assertSame(409, $exception->getStatusCode());
        }
        $service->refreshDraft($actor, $invoice->id, 1);
        self::assertSame('218.00', $invoice->fresh()->grand_total);
        $service->approve($approver, $invoice->id, 2);
        self::assertSame('approved', $invoice->fresh()->status);
        self::assertSame('218.00', $invoice->fresh()->snapshot['totals']['total']);
        BudgetLineVersion::query()->firstOrFail()->cells()->firstOrFail()
            ->update(['quantity' => '3.0000']);
        self::assertSame('218.00', $invoice->fresh()->snapshot['totals']['total']);
    }

    /** @return array{User,BillingCase,int} */
    private function financialCase(): array
    {
        $now = now();
        $typeId = DB::table('chapter_types')->insertGetId(['name' => 'Universitario', 'created_at' => $now, 'updated_at' => $now]);
        $countryId = DB::table('countries')->insertGetId(['name' => 'Ecuador', 'created_at' => $now, 'updated_at' => $now]);
        $chapterId = DB::table('chapters')->insertGetId([
            'chapter_type_id' => $typeId, 'name' => 'Capítulo prueba', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $teamId = DB::table('teams')->insertGetId([
            'chapter_id' => $chapterId, 'name' => 'Equipo prueba', 'credit_balance' => '0.00',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $projectId = DB::table('projects')->insertGetId([
            'country_id' => $countryId, 'code' => 'PR-01', 'name' => 'Proyecto prueba',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $phaseId = DB::table('trip_phases')->insertGetId([
            'project_id' => $projectId, 'team_id' => $teamId, 'phase' => 'Initial Visit',
            'starts_on' => '2026-11-01', 'ends_on' => '2026-11-02',
            'volunteer_count' => 1, 'staff_count' => 1, 'status' => 'draft',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $contactId = DB::table('contact_people')->insertGetId([
            'full_name' => 'Contacto prueba', 'email' => 'contacto@example.test',
            'phone' => '5551234', 'physical_address' => 'Dirección prueba',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('contact_assignments')->insert([
            'contact_person_id' => $contactId, 'chapter_id' => $chapterId, 'team_id' => $teamId,
            'role' => 'Billing Contact', 'is_billing_contact' => true,
            'is_email_recipient' => true, 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $actor = User::query()->create([
            'name' => 'Creador', 'username' => 'creador', 'password' => 'ClaveTemporal123!',
            'is_active' => true, 'must_change_password' => false,
        ]);
        $actor->forceFill(['scope_all' => true])->save();
        $role = Role::query()->create(['code' => 'facturador', 'name' => 'Facturador']);
        foreach (['invoices.manage', 'invoices.approve', 'invoices.void'] as $code) {
            $permission = Permission::query()->create(['code' => $code, 'module' => 'invoices', 'name' => $code]);
            $role->permissions()->attach($permission);
        }
        $actor->roles()->attach($role);
        $case = BillingCase::query()->create([
            'trip_phase_id' => $phaseId, 'invoice_type' => 'IC',
            'billing_mode' => 'standard', 'metadata' => ['document_date' => '2026-10-26'],
        ]);
        $line = BudgetLine::query()->create([
            'billing_case_id' => $case->id, 'catalog_key' => 'ic.airport_pickup',
            'description_en' => 'Airport Pickup', 'unit' => 'day', 'section' => 'Transportation',
            'bucket' => 'dr', 'arranged_eligible' => true, 'sort_order' => 1,
        ]);
        $version = BudgetLineVersion::query()->create([
            'budget_line_id' => $line->id, 'stage' => 'initial', 'unit_cost' => '60.0000',
        ]);
        $version->cells()->create(['spent_on' => '2026-11-01', 'quantity' => '1.0000']);
        return [$actor, $case, $contactId];
    }
}
