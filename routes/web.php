<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\BrandingLogoController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ChapterController;
use App\Http\Controllers\ContactAssignmentController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ActualExpenseController;
use App\Http\Controllers\AccountingController;
use App\Http\Controllers\AccountingImportController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\DraftBudgetPdfController;
use App\Http\Controllers\EmailTemplateController;
use App\Http\Controllers\EstimatedExpenseController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoiceEmailController;
use App\Http\Controllers\InvoicePdfController;
use App\Http\Controllers\OperationStatusController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SystemSettingController;
use App\Http\Controllers\SystemNotificationController;
use App\Http\Controllers\SuperadminController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TripPhaseController;
use App\Http\Controllers\UserThemePreferenceController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/branding/logo', [BrandingLogoController::class, 'show'])->name('branding.logo');

Route::get('/dashboard', DashboardController::class)->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/catalogos', [CatalogController::class, 'index'])->middleware('permission:catalogs.view,catalogs.manage')->name('catalogs.index');
    Route::post('/catalogos/{catalog}', [CatalogController::class, 'store'])
        ->middleware('permission:catalogs.manage')
        ->whereIn('catalog', ['chapter-types', 'countries', 'communities', 'universities', 'expense-categories'])
        ->name('catalogs.store');
    Route::patch('/catalogos/{catalog}/{id}', [CatalogController::class, 'update'])
        ->middleware('permission:catalogs.manage')
        ->whereIn('catalog', ['chapter-types', 'countries', 'communities', 'universities', 'expense-categories'])
        ->whereNumber('id')
        ->name('catalogs.update');
    Route::delete('/catalogos/{catalog}/{id}', [CatalogController::class, 'destroy'])
        ->middleware('permission:catalogs.manage')
        ->whereIn('catalog', ['chapter-types', 'countries', 'communities', 'universities', 'expense-categories'])
        ->whereNumber('id')
        ->name('catalogs.destroy');

    Route::get('/capitulos', [ChapterController::class, 'index'])->middleware('permission:chapters.view,chapters.manage')->name('chapters.index');
    Route::post('/capitulos', [ChapterController::class, 'store'])->middleware('permission:chapters.manage')->name('chapters.store');
    Route::patch('/capitulos/{chapter}', [ChapterController::class, 'update'])->middleware('permission:chapters.manage')->name('chapters.update');
    Route::delete('/capitulos/{chapter}', [ChapterController::class, 'destroy'])->middleware('permission:chapters.manage')->name('chapters.destroy');

    Route::post('/equipos', [TeamController::class, 'store'])->middleware('permission:chapters.manage')->name('teams.store');
    Route::patch('/equipos/{team}', [TeamController::class, 'update'])->middleware('permission:chapters.manage')->name('teams.update');
    Route::delete('/equipos/{team}', [TeamController::class, 'destroy'])->middleware('permission:chapters.manage')->name('teams.destroy');

    Route::get('/contactos', [ContactController::class, 'index'])->middleware('permission:contacts.view,contacts.manage')->name('contacts.index');
    Route::post('/contactos', [ContactController::class, 'store'])->middleware('permission:contacts.manage')->name('contacts.store');
    Route::patch('/contactos/{contact}', [ContactController::class, 'update'])->middleware('permission:contacts.manage')->name('contacts.update');
    Route::delete('/contactos/{contact}', [ContactController::class, 'destroy'])->middleware('permission:contacts.manage')->name('contacts.destroy');

    Route::post('/asignaciones-contacto', [ContactAssignmentController::class, 'store'])->middleware('permission:contacts.manage')->name('contact-assignments.store');
    Route::patch('/asignaciones-contacto/{assignment}', [ContactAssignmentController::class, 'update'])->middleware('permission:contacts.manage')->name('contact-assignments.update');
    Route::delete('/asignaciones-contacto/{assignment}', [ContactAssignmentController::class, 'destroy'])->middleware('permission:contacts.manage')->name('contact-assignments.destroy');

    Route::get('/proyectos', [ProjectController::class, 'index'])->middleware('permission:projects.view,projects.manage')->name('projects.index');
    Route::post('/proyectos', [ProjectController::class, 'store'])->middleware('permission:projects.manage')->name('projects.store');
    Route::patch('/proyectos/{project}', [ProjectController::class, 'update'])->middleware('permission:projects.manage')->name('projects.update');
    Route::delete('/proyectos/{project}', [ProjectController::class, 'destroy'])->middleware('permission:projects.manage')->name('projects.destroy');

    Route::get('/viajes', [TripPhaseController::class, 'index'])->middleware('permission:trip_phases.view,trip_phases.manage')->name('trip-phases.index');
    Route::post('/viajes', [TripPhaseController::class, 'store'])->middleware('permission:trip_phases.manage')->name('trip-phases.store');
    Route::patch('/viajes/{tripPhase}', [TripPhaseController::class, 'update'])->middleware('permission:trip_phases.manage')->name('trip-phases.update');
    Route::delete('/viajes/{tripPhase}', [TripPhaseController::class, 'destroy'])->middleware('permission:trip_phases.manage')->name('trip-phases.destroy');

    Route::get('/draft-budget', [EstimatedExpenseController::class, 'index'])->middleware('permission:budgets.view,budgets.manage')->name('estimated-expenses.index');
    Route::post('/draft-budget', [EstimatedExpenseController::class, 'store'])->middleware('permission:budgets.manage')->name('estimated-expenses.store');
    Route::patch('/draft-budget/{estimatedExpense}', [EstimatedExpenseController::class, 'update'])->middleware('permission:budgets.manage')->name('estimated-expenses.update');
    Route::delete('/draft-budget/{estimatedExpense}', [EstimatedExpenseController::class, 'destroy'])->middleware('permission:budgets.manage')->name('estimated-expenses.destroy');
    Route::post('/viajes/{tripPhase}/draft-budget-pdf', [DraftBudgetPdfController::class, 'store'])->middleware('permission:budgets.manage')->name('draft-budget-pdf.store');
    Route::get('/viajes/{tripPhase}/draft-budget-pdf', [DraftBudgetPdfController::class, 'show'])->middleware('permission:budgets.view,budgets.manage')->name('draft-budget-pdf.show');

    Route::get('/gastos-reales', [ActualExpenseController::class, 'index'])->middleware('permission:actual_expenses.view,actual_expenses.manage')->name('actual-expenses.index');
    Route::post('/gastos-reales', [ActualExpenseController::class, 'store'])->middleware('permission:actual_expenses.manage')->name('actual-expenses.store');
    Route::patch('/gastos-reales/{actualExpense}', [ActualExpenseController::class, 'update'])->middleware('permission:actual_expenses.manage')->name('actual-expenses.update');
    Route::delete('/gastos-reales/{actualExpense}', [ActualExpenseController::class, 'destroy'])->middleware('permission:actual_expenses.manage')->name('actual-expenses.destroy');

    Route::get('/recibos', [ReceiptController::class, 'index'])->middleware('permission:receipts.view,receipts.manage')->name('receipts.index');
    Route::post('/recibos', [ReceiptController::class, 'store'])->middleware('permission:receipts.manage')->name('receipts.store');
    Route::patch('/recibos/{receipt}', [ReceiptController::class, 'update'])->middleware('permission:receipts.manage')->name('receipts.update');
    Route::delete('/recibos/{receipt}', [ReceiptController::class, 'destroy'])->middleware('permission:receipts.manage')->name('receipts.destroy');
    Route::get('/recibos/{receipt}/download', [ReceiptController::class, 'show'])->middleware('permission:receipts.view,receipts.manage')->name('receipts.show');

    Route::get('/invoices', [InvoiceController::class, 'index'])->middleware('permission:invoices.view,invoices.manage')->name('invoices.index');
    Route::post('/invoices', [InvoiceController::class, 'store'])->middleware('permission:invoices.manage')->name('invoices.store');
    Route::patch('/invoices/{invoice}', [InvoiceController::class, 'update'])->middleware('permission:invoices.manage')->name('invoices.update');
    Route::post('/invoices/{invoice}/approve', [InvoiceController::class, 'approve'])->middleware('permission:invoices.manage')->name('invoices.approve');
    Route::post('/invoices/{invoice}/pdf', [InvoicePdfController::class, 'store'])->middleware('permission:invoices.manage')->name('invoices.pdf.store');
    Route::get('/invoices/{invoice}/pdf', [InvoicePdfController::class, 'show'])->middleware('permission:invoices.view,invoices.manage')->name('invoices.pdf.show');
    Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])->middleware('permission:invoices.manage')->name('invoices.destroy');

    Route::get('/correos-invoices', [InvoiceEmailController::class, 'index'])->middleware('permission:invoice_emails.view,invoice_emails.manage')->name('invoice-emails.index');
    Route::post('/correos-invoices/{invoice}', [InvoiceEmailController::class, 'store'])->middleware('permission:invoice_emails.manage')->name('invoice-emails.store');
    Route::post('/correos-invoices/logs/{emailLog}/send', [InvoiceEmailController::class, 'send'])->middleware('permission:invoice_emails.manage')->name('invoice-emails.send');
    Route::post('/plantillas-correo', [EmailTemplateController::class, 'store'])->middleware('permission:invoice_emails.manage')->name('email-templates.store');
    Route::patch('/plantillas-correo/{template}', [EmailTemplateController::class, 'update'])->middleware('permission:invoice_emails.manage')->name('email-templates.update');

    Route::get('/contabilidad', [AccountingController::class, 'index'])->middleware('permission:accounting.view,accounting.manage')->name('accounting.index');
    Route::patch('/contabilidad/invoices/{invoice}', [AccountingController::class, 'update'])->middleware('permission:accounting.manage')->name('accounting.update');
    Route::post('/contabilidad/import/preview', [AccountingImportController::class, 'preview'])->middleware('permission:accounting.manage')->name('accounting-import.preview');
    Route::post('/contabilidad/import/apply', [AccountingImportController::class, 'apply'])->middleware('permission:accounting.manage')->name('accounting-import.apply');

    Route::get('/reportes', [ReportController::class, 'index'])->middleware('permission:reports.view')->name('reports.index');
    Route::get('/reportes/export', [ReportController::class, 'export'])->middleware('permission:reports.view')->name('reports.export');

    Route::get('/alertas', [AlertController::class, 'index'])->middleware('permission:alerts.view')->name('alerts.index');

    Route::get('/auditoria', [AuditLogController::class, 'index'])->middleware('permission:audit_logs.view')->name('audit-logs.index');

    Route::get('/operaciones', OperationStatusController::class)->middleware('permission:operations.view')->name('operations.index');

    Route::get('/backups', [BackupController::class, 'index'])->middleware('permission:backups.view,backups.manage')->name('backups.index');
    Route::post('/backups/database', [BackupController::class, 'store'])->middleware('permission:backups.manage')->name('backups.store');
    Route::get('/backups/{backup}/download', [BackupController::class, 'show'])->middleware('permission:backups.view,backups.manage')->name('backups.show');

    Route::get('/configuracion', [SystemSettingController::class, 'edit'])->middleware('permission:settings.manage')->name('system-settings.edit');
    Route::post('/configuracion', [SystemSettingController::class, 'update'])->middleware('permission:settings.manage')->name('system-settings.update');

    Route::get('/superadmin', [SuperadminController::class, 'index'])->middleware('permission:superadmin.manage')->name('superadmin.index');
    Route::post('/superadmin/users', [SuperadminController::class, 'storeUser'])->middleware('permission:superadmin.manage')->name('superadmin.users.store');
    Route::patch('/superadmin/users/{user}', [SuperadminController::class, 'updateUser'])->middleware('permission:superadmin.manage')->name('superadmin.users.update');
    Route::post('/superadmin/roles', [SuperadminController::class, 'storeRole'])->middleware('permission:superadmin.manage')->name('superadmin.roles.store');
    Route::patch('/superadmin/roles/{role}', [SuperadminController::class, 'updateRole'])->middleware('permission:superadmin.manage')->name('superadmin.roles.update');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::patch('/theme-preference', [UserThemePreferenceController::class, 'update'])->name('theme-preference.update');

    Route::get('/notificaciones', [SystemNotificationController::class, 'index'])->name('notifications.index');
    Route::patch('/notificaciones/{notification}/leer', [SystemNotificationController::class, 'markRead'])->name('notifications.read');
    Route::patch('/notificaciones/leer-todas', [SystemNotificationController::class, 'markAllRead'])->name('notifications.read-all');
});

require __DIR__.'/auth.php';
