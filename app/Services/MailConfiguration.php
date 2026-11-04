<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MailSetting;
use Illuminate\Support\Facades\Schema;

final class MailConfiguration
{
    private ?string $signature = null;

    public function current(): ?MailSetting
    {
        return Schema::hasTable('mail_settings') ? MailSetting::query()->find(1) : null;
    }

    public function apply(): void
    {
        $settings = $this->current();
        if (! $settings) {
            return;
        }
        $signature = hash('sha256', json_encode([$settings->account, $settings->from_name, $settings->enabled,
            $settings->version, $settings->getRawOriginal('app_password')], JSON_THROW_ON_ERROR));
        if ($signature === $this->signature) {
            return;
        }
        $this->signature = $signature;
        config(['mail.default' => 'smtp', 'mail.from.address' => $settings->account,
            'mail.from.name' => $settings->from_name, 'mail.invoice_queue_enabled' => $settings->enabled,
            'mail.mailers.smtp.host' => 'smtp.gmail.com', 'mail.mailers.smtp.port' => 465,
            'mail.mailers.smtp.scheme' => 'smtps', 'mail.mailers.smtp.require_tls' => true,
            'mail.mailers.smtp.url' => null, 'mail.mailers.smtp.username' => $settings->account,
            'mail.mailers.smtp.password' => $settings->app_password, 'mail.mailers.smtp.timeout' => 30,
            'services.transactional_mail.provider' => 'smtp']);
        // Los workers recargan el transporte cuando cambian las credenciales, sin reiniciar Docker.
        if (app()->resolved('mail.manager')) {
            app('mail.manager')->purge('smtp');
        }
    }

    public function publicStatus(): array
    {
        $this->apply();
        $current = $this->current();

        return ['account' => $current?->account ?? config('mail.mailers.smtp.username', ''),
            'from_name' => $current?->from_name ?? config('mail.from.name', 'FIEA'),
            'enabled' => $current?->enabled ?? (bool) config('mail.invoice_queue_enabled'),
            'password_configured' => filled($current?->getRawOriginal('app_password') ?? config('mail.mailers.smtp.password')),
            'version' => $current?->version ?? 0];
    }
}
