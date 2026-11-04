<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\MailPreparationException;
use App\Models\EmailLog;
use App\Models\StorageFile;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class TransactionalMailService
{
    /**
     * @param  array<int, string>  $to
     * @param  array<int, string>  $cc
     * @param  array<int, string>  $bcc
     */
    public function sendInvoice(EmailLog $emailLog, array $to, array $cc = [], array $bcc = []): string
    {
        app(MailConfiguration::class)->apply();

        return match ((string) config('services.transactional_mail.provider', 'resend')) {
            'resend' => $this->sendWithResend($emailLog, $to, $cc, $bcc),
            'smtp' => $this->sendWithSmtp($emailLog, $to, $cc, $bcc),
            default => throw new MailPreparationException('Proveedor de correo transaccional no soportado.'),
        };
    }

    /**
     * @param  array<int, string>  $to
     * @param  array<int, string>  $cc
     * @param  array<int, string>  $bcc
     */
    private function sendWithSmtp(EmailLog $emailLog, array $to, array $cc, array $bcc): string
    {
        try {
            if (! filled(config('mail.mailers.smtp.host'))
                || ! filled(config('mail.mailers.smtp.username'))
                || ! filled(config('mail.mailers.smtp.password'))
                || ! config('mail.mailers.smtp.require_tls')) {
                throw new RuntimeException('SMTP requiere servidor, credenciales y TLS obligatorio.');
            }
            if (! $emailLog->delivery_key || ! $emailLog->recipients_snapshot || ! $emailLog->attachment_snapshot) {
                throw new RuntimeException('El correo no tiene una preparación inmutable.');
            }
            $attachments = $this->attachments($emailLog);
            $html = view('mail.invoice-prepared', [
                'body' => $emailLog->body, 'invoice' => $emailLog->invoice,
            ])->render();
            $domain = substr(strrchr((string) config('mail.from.address'), '@') ?: '', 1);
            $messageId = 'invoice-email-'.$emailLog->delivery_key.'@'.$domain;
            $mailer = Mail::mailer('smtp');
        } catch (\Throwable $exception) {
            throw new MailPreparationException('La preparación local del correo no está disponible.', 0, $exception);
        }

        $sent = $mailer->html($html, function (Message $message) use ($emailLog, $to, $cc, $bcc, $attachments, $messageId): void {
            $message->from((string) config('mail.from.address'), (string) config('mail.from.name'))
                ->to($to)->subject($emailLog->subject);
            if ($cc !== []) {
                $message->cc($cc);
            }
            if ($bcc !== []) {
                $message->bcc($bcc);
            }
            $message->getSymfonyMessage()->text($emailLog->body)
                ->getHeaders()->addIdHeader('Message-ID', $messageId);
            foreach ($attachments as $attachment) {
                $message->attachData(base64_decode($attachment['content'], true), $attachment['filename'], [
                    'mime' => $attachment['content_type'],
                ]);
            }
        });

        return $sent?->getMessageId() ?? '';
    }

    /**
     * @param  array<int, string>  $to
     * @param  array<int, string>  $cc
     * @param  array<int, string>  $bcc
     */
    private function sendWithResend(EmailLog $emailLog, array $to, array $cc, array $bcc): string
    {
        try {
            $apiKey = (string) config('services.resend.key');

            if ($apiKey === '') {
                throw new RuntimeException('RESEND_API_KEY no esta configurado.');
            }

            $payload = array_filter([
                'from' => $this->fromAddress(),
                'to' => $to,
                'cc' => $cc,
                'bcc' => $bcc,
                'subject' => $emailLog->subject,
                'html' => view('mail.invoice-prepared', [
                    'body' => $emailLog->body,
                    'invoice' => $emailLog->invoice,
                ])->render(),
                'text' => $emailLog->body,
                'attachments' => $this->attachments($emailLog),
                'tags' => [
                    ['name' => 'module', 'value' => 'invoice_emails'],
                    ['name' => 'email_log_id', 'value' => (string) $emailLog->id],
                ],
            ], fn ($value) => $value !== [] && $value !== null && $value !== '');

            $endpoint = (string) config('services.resend.endpoint');
            if (parse_url($endpoint, PHP_URL_SCHEME) !== 'https'
                || parse_url($endpoint, PHP_URL_HOST) !== 'api.resend.com') {
                throw new RuntimeException('Endpoint de correo transaccional no permitido.');
            }
            if (! $emailLog->delivery_key || ! $emailLog->recipients_snapshot || ! $emailLog->attachment_snapshot) {
                throw new RuntimeException('El correo no tiene una preparación inmutable.');
            }
        } catch (\Throwable $exception) {
            throw new MailPreparationException('La preparación local del correo no está disponible.', 0, $exception);
        }
        $response = Http::connectTimeout(5)->timeout(30)
            ->withToken($apiKey)
            ->withHeaders([
                'Idempotency-Key' => 'invoice-email-'.$emailLog->delivery_key,
            ])
            ->post($endpoint, $payload);

        if ($response->failed()) {
            throw new RuntimeException('El proveedor rechazó el correo.');
        }

        return (string) ($response->json('id') ?? '');
    }

    private function fromAddress(): string
    {
        $address = (string) config('mail.from.address');
        $name = trim((string) config('mail.from.name'));

        return $name !== '' ? "{$name} <{$address}>" : $address;
    }

    /**
     * @return array<int, array{filename: string, content: string, content_type: string}>
     */
    private function attachments(EmailLog $emailLog): array
    {
        $snapshot = $emailLog->attachment_snapshot;
        $file = StorageFile::query()->find($snapshot['storage_file_id'] ?? null);

        if (! $file || ! hash_equals((string) ($snapshot['checksum'] ?? ''), (string) $file->checksum)
            || ! Storage::disk($file->diskName())->exists($file->object_key)) {
            throw new RuntimeException('El adjunto congelado no está disponible.');
        }

        $bytes = Storage::disk($file->diskName())->get($file->object_key);
        if (! hash_equals((string) $snapshot['checksum'], hash('sha256', $bytes)) || strlen($bytes) !== (int) $file->size_bytes) {
            throw new RuntimeException('El contenido del adjunto no coincide con su preparación.');
        }

        return [[
            'filename' => $snapshot['filename'],
            'content' => base64_encode($bytes),
            'content_type' => $snapshot['mime_type'],
        ]];
    }
}
