<?php

declare(strict_types=1);

namespace App\Services;

final class InvoiceEmailDispatchPolicy
{
    public function windowSeconds(): int
    {
        app(MailConfiguration::class)->apply();

        return match (config('services.transactional_mail.provider')) {
            'smtp' => (int) config('mail.invoice_dispatch_window_seconds', 86400),
            'resend' => (int) config('services.resend.idempotency_window_seconds', 0),
            default => 0,
        };
    }
}
