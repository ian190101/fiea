<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Services\BrandingAssetService;
use App\Services\FileStorageService;
use App\Services\UploadedFileStorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SystemSettingController extends Controller
{
    public function __construct(
        private readonly UploadedFileStorageService $fileStorage,
        private readonly FileStorageService $files,
        private readonly BrandingAssetService $brandingAssets,
    )
    {
    }

    public function edit(): Response
    {
        $settings = SystemSetting::query()->with(['logoFile', 'updatedBy:id,name,username'])->firstOrCreate(['id' => 1]);

        return Inertia::render('SystemSettings/Edit', [
            'settings' => [
                'primary_color' => $settings->primary_color,
                'secondary_color' => $settings->secondary_color,
                'accent_color' => $settings->accent_color,
                'lock_final_invoice_by_default' => $settings->lock_final_invoice_by_default,
                'accounting_can_edit_summary' => $settings->accounting_can_edit_summary,
                'logo' => $settings->logoFile ? [
                    'id' => $settings->logoFile->id,
                    'original_name' => $settings->logoFile->original_name,
                    'public_url' => $settings->logoFile->public_url,
                    'provider' => $settings->logoFile->provider,
                ] : null,
                'updated_by' => $settings->updatedBy,
                'updated_at' => $settings->updated_at,
            ],
            'storageStatus' => Inertia::defer(fn () => $this->files->status(), 'settings'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'primary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'accent_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'lock_final_invoice_by_default' => ['required', 'boolean'],
            'accounting_can_edit_summary' => ['required', 'boolean'],
            'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp,svg', 'max:4096'],
        ]);

        $settings = SystemSetting::query()->with('logoFile')->firstOrCreate(['id' => 1]);
        $before = $settings->only([
            'logo_file_id',
            'primary_color',
            'secondary_color',
            'accent_color',
            'lock_final_invoice_by_default',
            'accounting_can_edit_summary',
        ]);

        DB::transaction(function () use ($request, $settings, $data, $before) {
            $payload = [
                'primary_color' => strtolower($data['primary_color']),
                'secondary_color' => strtolower($data['secondary_color']),
                'accent_color' => strtolower($data['accent_color']),
                'lock_final_invoice_by_default' => (bool) $data['lock_final_invoice_by_default'],
                'accounting_can_edit_summary' => (bool) $data['accounting_can_edit_summary'],
                'updated_by_id' => $request->user()?->id,
            ];

            if ($request->hasFile('logo')) {
                $payload['logo_file_id'] = $this->fileStorage
                    ->store($request->file('logo'), 'branding/logos', $request->user())
                    ->id;
            }

            $settings->fill($payload)->save();

            AuditLog::query()->create([
                'user_id' => $request->user()?->id,
                'action' => 'system_settings_updated',
                'module' => 'settings',
                'auditable_type' => SystemSetting::class,
                'auditable_id' => $settings->id,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'metadata' => [
                    'before' => $before,
                    'after' => $settings->only([
                        'logo_file_id',
                        'primary_color',
                        'secondary_color',
                        'accent_color',
                        'lock_final_invoice_by_default',
                        'accounting_can_edit_summary',
                    ]),
                ],
            ]);
        });

        $this->brandingAssets->flushCache();
        Cache::forget('fiea:inertia:settings');

        return back()->with('success', 'Configuracion actualizada correctamente.');
    }
}
