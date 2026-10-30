<?php

namespace App\Http\Controllers;

use App\Actions\Settings\UpdateSystemSettings;
use App\Http\Requests\UpdateSystemSettingsRequest;
use App\Models\SystemSetting;
use App\Services\FileStorageService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SystemSettingController extends Controller
{
    public function __construct(private readonly FileStorageService $files) {}

    public function edit(): Response
    {
        $settings = SystemSetting::query()->with(['logoFile', 'updatedBy:id,name,username'])->firstOrCreate(['id' => 1]);

        return Inertia::render('SystemSettings/Edit', [
            'settings' => [
                'primary_color' => $settings->primary_color,
                'secondary_color' => $settings->secondary_color,
                'accent_color' => $settings->accent_color,
                'logo' => $settings->logoFile ? [
                    'id' => $settings->logoFile->id,
                    'original_name' => $settings->logoFile->original_name,
                ] : null,
                'updated_by' => $settings->updatedBy,
                'updated_at' => $settings->updated_at,
            ],
            'storageStatus' => Inertia::defer(fn () => $this->files->status(), 'settings'),
        ]);
    }

    public function update(UpdateSystemSettingsRequest $request,
        UpdateSystemSettings $action): RedirectResponse
    {
        $action->execute($request->user(), $request->safe()->except('logo'), $request->file('logo'));

        return back()->with('success', 'Configuracion actualizada correctamente.');
    }
}
