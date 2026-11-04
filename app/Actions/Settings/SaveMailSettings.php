<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Models\AuditLog;
use App\Models\MailSetting;
use App\Models\User;
use App\Services\MailConfiguration;
use App\Services\SmtpConnectionVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveMailSettings
{
    public function __construct(private readonly MailConfiguration $configuration,
        private readonly SmtpConnectionVerifier $verifier) {}

    public function execute(User $actor, array $data): MailSetting
    {
        abort_unless($actor->hasPermission('settings.manage'), 403);
        $current = $this->configuration->publicStatus();
        abort_unless((int) $current['version'] === (int) $data['version'], 409, 'La configuración cambió. Recarga antes de guardar.');
        $saved = $this->configuration->current();
        $account = strtolower(trim($data['account']));
        $password = $data['app_password'] ?? null;
        if (! $password && strcasecmp($account, (string) $current['account']) !== 0) {
            throw ValidationException::withMessages(['app_password' => 'Introduce la contraseña de aplicación de la nueva cuenta.']);
        }
        $password = $password ?: ($saved?->app_password ?? config('mail.mailers.smtp.password'));
        if (! filled($password)) {
            throw ValidationException::withMessages(['app_password' => 'Introduce una contraseña de aplicación de Gmail.']);
        }
        $this->verifier->verify($account, $password);

        $settings = DB::transaction(function () use ($actor, $data, $account, $password): MailSetting {
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($actor->hasPermission('settings.manage'), 403);
            $settings = MailSetting::query()->lockForUpdate()->find(1);
            abort_unless(($settings?->version ?? 0) === (int) $data['version'], 409, 'La configuración cambió. Recarga antes de guardar.');
            $settings ??= new MailSetting;
            $settings->forceFill(['id' => 1, 'account' => $account, 'from_name' => $data['from_name'],
                'app_password' => $password, 'enabled' => $data['enabled'],
                'version' => ($settings->version ?? 0) + 1, 'updated_by_id' => $actor->id])->save();
            AuditLog::query()->create(['user_id' => $actor->id, 'action' => 'mail_settings_updated',
                'module' => 'settings', 'auditable_type' => MailSetting::class, 'auditable_id' => $settings->id,
                'metadata' => ['account' => $account, 'enabled' => $settings->enabled,
                    'password_changed' => filled($data['app_password'] ?? null)]]);

            return $settings;
        });
        $this->configuration->apply();

        return $settings;
    }
}
