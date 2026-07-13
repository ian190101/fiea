<?php

namespace Database\Seeders;

use App\Models\ActualExpense;
use App\Models\Chapter;
use App\Models\ChapterType;
use App\Models\Community;
use App\Models\ContactAssignment;
use App\Models\ContactPerson;
use App\Models\Country;
use App\Models\EstimatedExpense;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\InvoiceRecipient;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\StorageFile;
use App\Models\SystemSetting;
use App\Models\Team;
use App\Models\TripPhase;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TidbBootstrapDemoSeeder extends Seeder
{
    public function run(): void
    {
        $password = env('FIEA_BOOTSTRAP_ADMIN_PASSWORD');

        if (! is_string($password) || mb_strlen($password) < 14) {
            throw new \RuntimeException('Define FIEA_BOOTSTRAP_ADMIN_PASSWORD con minimo 14 caracteres antes de ejecutar este seeder.');
        }

        DB::transaction(function () use ($password): void {
            $permissions = collect($this->permissions())->map(
                fn (array $permission) => Permission::query()->updateOrCreate(
                    ['code' => $permission['code']],
                    $permission
                )
            );

            $superadmin = Role::query()->updateOrCreate(
                ['code' => 'superadmin'],
                ['name' => 'Superadministrador', 'description' => 'Acceso total al sistema FIEA.']
            );
            $superadmin->permissions()->sync($permissions->pluck('id'));

            $admin = User::query()->updateOrCreate(
                ['username' => 'admin'],
                [
                    'name' => 'Administrador FIEA',
                    'email' => 'admin@fiea.local',
                    'password' => $password,
                    'must_change_password' => true,
                    'theme_preference' => 'system',
                    'is_active' => true,
                ]
            );
            $admin->roles()->syncWithoutDetaching([$superadmin->id]);

            $country = Country::query()->firstOrCreate(['name' => 'Ecuador']);
            $community = Community::query()->firstOrCreate(
                ['country_id' => $country->id, 'name' => 'Santa Rosa de Cerritos'],
                ['description' => 'Comunidad de prueba para flujo de invoices y presupuesto.']
            );

            $chapterType = ChapterType::query()->firstOrCreate(['name' => 'Universitario']);
            $chapter = Chapter::query()->firstOrCreate(
                ['name' => 'EWB Missouri S&T'],
                [
                    'chapter_type_id' => $chapterType->id,
                    'description' => 'Capitulo de prueba para validacion del flujo completo.',
                ]
            );
            $chapter->update(['chapter_type_id' => $chapterType->id]);

            $team = Team::query()->firstOrCreate(
                ['chapter_id' => $chapter->id, 'name' => 'Missouri S&T Travel Team'],
                ['description' => 'Equipo demo con creditos arrastrables.', 'credit_balance' => 350.00]
            );

            $contact = ContactPerson::query()->updateOrCreate(
                ['email' => 'billing.demo@fieaecuador.org'],
                [
                    'full_name' => 'Billing Contact Demo',
                    'phone' => '+1 555 0100',
                    'physical_address' => '123 Demo Street, Rolla, MO',
                ]
            );

            ContactAssignment::query()->updateOrCreate(
                ['contact_person_id' => $contact->id, 'team_id' => $team->id, 'role' => 'Billing'],
                [
                    'chapter_id' => $chapter->id,
                    'is_billing_contact' => true,
                    'is_email_recipient' => true,
                    'is_active' => true,
                ]
            );

            $project = Project::query()->updateOrCreate(
                ['code' => 'SRC-2026'],
                [
                    'country_id' => $country->id,
                    'community_id' => $community->id,
                    'name' => 'Santa Rosa de Cerritos Water System',
                    'started_on' => '2026-06-01',
                    'closed_on' => null,
                    'description' => 'Proyecto demo para probar presupuesto, gastos, recibos e invoices.',
                ]
            );

            $phase = TripPhase::query()->updateOrCreate(
                ['project_id' => $project->id, 'team_id' => $team->id, 'phase' => 'Assessment Trip'],
                [
                    'assigned_technician_id' => $admin->id,
                    'starts_on' => '2026-08-10',
                    'ends_on' => '2026-08-20',
                    'volunteer_count' => 8,
                    'staff_count' => 2,
                    'status' => 'active',
                ]
            );

            $categories = collect([
                ['name' => 'Transportation', 'fund_type' => 'DR'],
                ['name' => 'Lodging', 'fund_type' => 'DR'],
                ['name' => 'Food', 'fund_type' => 'DR'],
                ['name' => 'Materials', 'fund_type' => 'DR'],
                ['name' => 'Bank Fees', 'fund_type' => 'WODR'],
            ])->mapWithKeys(fn (array $category) => [
                $category['name'] => ExpenseCategory::query()->firstOrCreate(['name' => $category['name']], $category),
            ]);

            $transportBudget = EstimatedExpense::query()->updateOrCreate(
                ['trip_phase_id' => $phase->id, 'description' => 'International transportation'],
                [
                    'expense_category_id' => $categories['Transportation']->id,
                    'unit' => 'ticket',
                    'initial_unit_cost' => 650.00,
                    'initial_quantity' => 8,
                    'estimated_total' => 5200.00,
                    'fund_type' => 'DR',
                ]
            );

            EstimatedExpense::query()->updateOrCreate(
                ['trip_phase_id' => $phase->id, 'description' => 'Lodging for team'],
                [
                    'expense_category_id' => $categories['Lodging']->id,
                    'unit' => 'night',
                    'initial_unit_cost' => 85.00,
                    'initial_quantity' => 10,
                    'estimated_total' => 850.00,
                    'fund_type' => 'DR',
                ]
            );

            $actual = ActualExpense::query()->updateOrCreate(
                ['trip_phase_id' => $phase->id, 'receipt_number' => 'DEMO-RCPT-001'],
                [
                    'estimated_expense_id' => $transportBudget->id,
                    'expense_category_id' => $categories['Transportation']->id,
                    'reported_by_id' => $admin->id,
                    'description' => 'Airline tickets purchased',
                    'unit' => 'ticket',
                    'final_unit_cost' => 640.00,
                    'final_quantity' => 8,
                    'real_total' => 5120.00,
                    'fund_type' => 'DR',
                    'reported_at' => Carbon::now(),
                ]
            );

            $receiptFile = StorageFile::query()->updateOrCreate(
                ['object_key' => 'demo/receipts/DEMO-RCPT-001.pdf'],
                [
                    'provider' => 'local',
                    'bucket' => 'demo',
                    'original_name' => 'DEMO-RCPT-001.pdf',
                    'mime_type' => 'application/pdf',
                    'size_bytes' => 1024,
                    'checksum' => 'demo-receipt-001',
                    'public_url' => null,
                    'uploaded_by_id' => $admin->id,
                ]
            );

            Receipt::query()->updateOrCreate(
                ['actual_expense_id' => $actual->id, 'receipt_number' => 'DEMO-RCPT-001'],
                [
                    'storage_file_id' => $receiptFile->id,
                    'issued_on' => '2026-07-10',
                    'amount' => 5120.00,
                ]
            );

            $invoice = Invoice::query()->updateOrCreate(
                ['code' => 'SRC-2026-IC-INITIAL-001'],
                [
                    'trip_phase_id' => $phase->id,
                    'contact_person_id' => $contact->id,
                    'created_by_id' => $admin->id,
                    'approved_by_id' => $admin->id,
                    'type' => 'IC',
                    'stage' => 'initial',
                    'status' => 'approved',
                    'total_dr' => 6050.00,
                    'total_wodr' => 0.00,
                    'grand_total' => 6050.00,
                    'balance_conciliation' => 930.00,
                ]
            );

            InvoiceRecipient::query()->updateOrCreate(
                ['invoice_id' => $invoice->id, 'email' => $contact->email],
                ['contact_person_id' => $contact->id, 'recipient_type' => 'to']
            );

            SystemSetting::query()->firstOrCreate(['id' => 1]);
        });

        $this->command?->info('Bootstrap TiDB completado: usuario admin superadmin y datos demo creados.');
    }

    /**
     * @return array<int, array{code: string, module: string, name: string}>
     */
    private function permissions(): array
    {
        return [
            ['code' => 'catalogs.view', 'module' => 'catalogs', 'name' => 'Ver catalogos'],
            ['code' => 'catalogs.manage', 'module' => 'catalogs', 'name' => 'Gestionar catalogos'],
            ['code' => 'chapters.view', 'module' => 'chapters', 'name' => 'Ver capitulos'],
            ['code' => 'chapters.manage', 'module' => 'chapters', 'name' => 'Gestionar capitulos y equipos'],
            ['code' => 'contacts.view', 'module' => 'contacts', 'name' => 'Ver contactos'],
            ['code' => 'contacts.manage', 'module' => 'contacts', 'name' => 'Gestionar contactos'],
            ['code' => 'projects.view', 'module' => 'projects', 'name' => 'Ver proyectos'],
            ['code' => 'projects.manage', 'module' => 'projects', 'name' => 'Gestionar proyectos'],
            ['code' => 'trip_phases.view', 'module' => 'trip_phases', 'name' => 'Ver viajes'],
            ['code' => 'trip_phases.manage', 'module' => 'trip_phases', 'name' => 'Gestionar viajes'],
            ['code' => 'budgets.view', 'module' => 'budgets', 'name' => 'Ver draft budget'],
            ['code' => 'budgets.manage', 'module' => 'budgets', 'name' => 'Gestionar draft budget'],
            ['code' => 'actual_expenses.view', 'module' => 'actual_expenses', 'name' => 'Ver gastos reales'],
            ['code' => 'actual_expenses.manage', 'module' => 'actual_expenses', 'name' => 'Gestionar gastos reales'],
            ['code' => 'receipts.view', 'module' => 'receipts', 'name' => 'Ver recibos'],
            ['code' => 'receipts.manage', 'module' => 'receipts', 'name' => 'Gestionar recibos'],
            ['code' => 'invoices.view', 'module' => 'invoices', 'name' => 'Ver invoices'],
            ['code' => 'invoices.manage', 'module' => 'invoices', 'name' => 'Gestionar invoices'],
            ['code' => 'invoice_emails.view', 'module' => 'invoice_emails', 'name' => 'Ver correos de invoices'],
            ['code' => 'invoice_emails.manage', 'module' => 'invoice_emails', 'name' => 'Preparar correos de invoices'],
            ['code' => 'accounting.view', 'module' => 'accounting', 'name' => 'Ver contabilidad'],
            ['code' => 'accounting.manage', 'module' => 'accounting', 'name' => 'Gestionar conciliacion contable'],
            ['code' => 'reports.view', 'module' => 'reports', 'name' => 'Ver reportes financieros'],
            ['code' => 'alerts.view', 'module' => 'alerts', 'name' => 'Ver centro de alertas'],
            ['code' => 'audit_logs.view', 'module' => 'audit_logs', 'name' => 'Ver auditoria'],
            ['code' => 'operations.view', 'module' => 'operations', 'name' => 'Ver estado operativo'],
            ['code' => 'backups.view', 'module' => 'backups', 'name' => 'Ver backups'],
            ['code' => 'backups.manage', 'module' => 'backups', 'name' => 'Generar backups'],
            ['code' => 'settings.manage', 'module' => 'settings', 'name' => 'Gestionar configuracion'],
            ['code' => 'superadmin.manage', 'module' => 'superadmin', 'name' => 'Gestionar usuarios, roles y permisos'],
        ];
    }
}
