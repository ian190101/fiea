<?php

namespace Database\Seeders;

use App\Models\ChapterType;
use App\Models\Country;
use App\Models\ExpenseCategory;
use App\Models\EmailTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $permissions = collect($this->permissions())
            ->map(fn (array $permission) => Permission::query()->firstOrCreate(
                ['code' => $permission['code']],
                $permission
            ));

        $roles = collect([
            ['code' => 'superadmin', 'name' => 'Superadministrador'],
            ['code' => 'administrativo', 'name' => 'Administrativo'],
            ['code' => 'tecnico', 'name' => 'Tecnico'],
            ['code' => 'contabilidad', 'name' => 'Contabilidad'],
        ])->map(fn (array $role) => Role::query()->firstOrCreate(['code' => $role['code']], $role));

        $roles->firstWhere('code', 'superadmin')?->permissions()->sync($permissions->pluck('id'));
        $roles->firstWhere('code', 'administrativo')?->permissions()->sync(
            $permissions->whereIn('code', [
                'catalogs.view', 'catalogs.manage',
                'chapters.view', 'chapters.manage',
                'contacts.view', 'contacts.manage',
                'projects.view', 'projects.manage',
                'trip_phases.view', 'trip_phases.manage',
                'budgets.view', 'budgets.manage',
                'actual_expenses.view', 'actual_expenses.manage',
                'receipts.view', 'receipts.manage',
                'invoices.view', 'invoices.manage',
                'invoice_emails.view', 'invoice_emails.manage',
                'reports.view',
                'alerts.view',
                'audit_logs.view',
            ])->pluck('id')
        );
        $roles->firstWhere('code', 'tecnico')?->permissions()->sync(
            $permissions->whereIn('code', [
                'projects.view',
                'trip_phases.view',
                'budgets.view', 'budgets.manage',
                'actual_expenses.view', 'actual_expenses.manage',
                'receipts.view', 'receipts.manage',
                'invoices.view',
                'alerts.view',
            ])->pluck('id')
        );
        $roles->firstWhere('code', 'contabilidad')?->permissions()->sync(
            $permissions->whereIn('code', [
                'invoices.view',
                'actual_expenses.view',
                'receipts.view',
                'accounting.view', 'accounting.manage',
                'reports.view',
                'alerts.view',
                'audit_logs.view',
            ])->pluck('id')
        );

        $user = User::query()->updateOrCreate(
            ['username' => 'admin'],
            [
                'name' => 'Administrador FIEA',
                'email' => 'admin@fiea.local',
                'password' => 'password',
                'must_change_password' => false,
                'theme_preference' => 'system',
                'is_active' => true,
            ]
        );

        $user->roles()->sync($roles->pluck('id'));

        ChapterType::query()->firstOrCreate(['name' => 'Universitario']);
        ChapterType::query()->firstOrCreate(['name' => 'Profesional']);
        Country::query()->firstOrCreate(['name' => 'Ecuador']);

        collect([
            ['name' => 'Transportation', 'fund_type' => 'DR'],
            ['name' => 'Services', 'fund_type' => 'DR', 'applies_service_fee' => true, 'service_fee_percentage' => 5],
            ['name' => 'Lodging', 'fund_type' => 'DR'],
            ['name' => 'Food', 'fund_type' => 'DR'],
            ['name' => 'Materials', 'fund_type' => 'DR'],
            ['name' => 'Bank Fees', 'fund_type' => 'WODR'],
            ['name' => 'Contingency', 'fund_type' => 'WODR', 'applies_contingency' => true],
        ])->each(fn (array $category) => ExpenseCategory::query()->firstOrCreate(
            ['name' => $category['name']],
            $category
        ));

        SystemSetting::query()->firstOrCreate(['id' => 1]);
        $this->seedEmailTemplates();
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

    private function seedEmailTemplates(): void
    {
        collect([
            ['invoice_type' => 'IC', 'stage' => 'initial', 'name' => 'IC Initial invoice'],
            ['invoice_type' => 'IC', 'stage' => 'final', 'name' => 'IC Final invoice'],
            ['invoice_type' => 'MAT', 'stage' => 'initial', 'name' => 'MAT Initial invoice'],
            ['invoice_type' => 'MAT', 'stage' => 'final', 'name' => 'MAT Final invoice'],
        ])->each(fn (array $template) => EmailTemplate::query()->firstOrCreate(
            ['invoice_type' => $template['invoice_type'], 'stage' => $template['stage']],
            [
                'name' => $template['name'],
                'subject_template' => 'FIEA {{invoice_type}} {{stage}} Invoice - {{project_code}}',
                'body_template' => implode("\n", [
                    'Hello,',
                    '',
                    'Please find the {{invoice_type}} {{stage}} invoice for {{project_code}} - {{project_name}} attached.',
                    '',
                    'Invoice code: {{invoice_code}}',
                    'Grand total: {{grand_total}}',
                    '',
                    'Best regards,',
                    'FIEA Team',
                ]),
                'is_active' => true,
            ]
        ));
    }
}
