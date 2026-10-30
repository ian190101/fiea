<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Models\AuditLog;
use App\Models\StorageFile;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\BrandingAssetService;
use App\Services\UploadedFileStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class UpdateSystemSettings
{
    public function __construct(private readonly UploadedFileStorageService $storage,
        private readonly BrandingAssetService $branding) {}

    public function execute(User $actor, array $data, ?UploadedFile $logo): SystemSetting
    {
        $stored = null;
        try {
            $settings = DB::transaction(function () use ($actor, $data, $logo, &$stored): SystemSetting {
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                abort_unless($actor->hasPermission('settings.manage'), 403);
                $settings = SystemSetting::query()->lockForUpdate()->firstOrCreate(['id' => 1]);
                $fields = ['logo_file_id', 'primary_color', 'secondary_color', 'accent_color'];
                $before = $settings->only($fields);
                $payload = collect($data)->only(['primary_color', 'secondary_color', 'accent_color'])->all();
                foreach (['primary_color', 'secondary_color', 'accent_color'] as $color) {
                    $payload[$color] = strtolower($payload[$color]);
                }
                $payload['updated_by_id'] = $actor->id;
                if ($logo) {
                    $stored = $this->storage->store($logo, 'branding/logos', $actor);
                    $payload['logo_file_id'] = $stored->id;
                }
                $settings->fill($payload)->save();
                AuditLog::query()->create([
                    'user_id' => $actor->id, 'action' => 'system_settings_updated', 'module' => 'settings',
                    'auditable_type' => SystemSetting::class, 'auditable_id' => $settings->id,
                    'metadata' => ['before' => $before, 'after' => $settings->only($fields)],
                ]);

                return $settings;
            }, 1);
        } catch (\Throwable $exception) {
            if ($stored instanceof StorageFile) {
                try {
                    Storage::disk($stored->diskName())->delete($stored->object_key);
                } catch (\Throwable $cleanup) {
                    report($cleanup);
                }
            }
            throw $exception;
        }
        $this->branding->flushCache();
        Cache::forget('fiea:inertia:settings');

        return $settings;
    }
}
