<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Settings\SaveMailSettings;
use App\Http\Requests\SaveMailSettingsRequest;
use App\Models\Invoice;
use App\Services\MailConfiguration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Mail;

final class MailSettingsController extends Controller
{
    public function update(SaveMailSettingsRequest $request, SaveMailSettings $action): RedirectResponse
    {
        $action->execute($request->user(), $request->validated());

        return back()->with('success', 'Gmail comprobado y configuración de correo guardada.');
    }

    public function test(MailConfiguration $configuration): RedirectResponse
    {
        $configuration->apply();
        $account = (string) config('mail.mailers.smtp.username');
        abort_unless(filter_var($account, FILTER_VALIDATE_EMAIL) && config('mail.default') === 'smtp'
            && filled(config('mail.mailers.smtp.password')), 422, 'Guarda primero una cuenta Gmail válida.');
        $body = "PRUEBA / TEST EMAIL - No payment is required.\n\nHello,\n\nThis is a test message to verify the FIEA email configuration.\n\nBest regards,\nFIEA Team";
        // La prueba solo se dirige a la cuenta configurada y no crea facturas operativas.
        Mail::send('mail.invoice-prepared', ['invoice' => new Invoice, 'body' => $body], function ($message) use ($account, $body): void {
            $message->to($account)->subject('[PRUEBA] FIEA - Comprobación de correo');
            $message->getSymfonyMessage()->text($body);
        });

        return back()->with('success', 'Correo de prueba enviado a '.$account.'.');
    }
}
