<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

class SmtpConnectionVerifier
{
    public function verify(string $account, string $password): void
    {
        $transport = new EsmtpTransport('smtp.gmail.com', 465, true);
        $transport->setUsername($account)->setPassword($password)->setRequireTls(true);
        $transport->getStream()->setTimeout(10);
        try {
            $transport->start();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['app_password' => 'Gmail no aceptó la conexión. Comprueba la cuenta y su contraseña de aplicación.']);
        } finally {
            try {
                $transport->stop();
            } catch (\Throwable) { /* El cierre no modifica el resultado de la autenticación. */
            }
        }
    }
}
