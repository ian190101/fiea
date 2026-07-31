<?php

namespace App\Services;

use App\Models\EmailLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class TransactionalMailService
{
    /**
     * @param array<int, string> $to
     * @param array<int, string> $cc
     * @param array<int, string> $bcc
     */
    public function sendInvoice(EmailLog $emailLog, array $to, array $cc = [], array $bcc = []): string
    {
        return match ((string) config('services.transactional_mail.provider', 'resend')) {
            'resend' => $this->sendWithResend($emailLog, $to, $cc, $bcc),
            default => throw new RuntimeException('Proveedor de correo transaccional no soportado.'),
        };
    }

    /**
     * @param array<int, string> $to
     * @param array<int, string> $cc
     * @param array<int, string> $bcc
     */
    private function sendWithResend(EmailLog $emailLog, array $to, array $cc, array $bcc): string
    {
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

        $response = Http::timeout((int) config('services.resend.timeout', 30))
            ->withToken($apiKey)
            ->withHeaders([
                'Idempotency-Key' => 'invoice-email-'.$emailLog->id.'-'.$emailLog->retry_count,
            ])
            ->post((string) config('services.resend.endpoint'), $payload);

        if ($response->failed()) {
            throw new RuntimeException('Resend rechazo el correo: '.$response->body());
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
        $file = $emailLog->invoice?->pdfFile;

        if (! $file || ! Storage::disk($file->diskName())->exists($file->object_key)) {
            return [];
        }

        return [[
            'filename' => $file->original_name,
            'content' => base64_encode(Storage::disk($file->diskName())->get($file->object_key)),
            'content_type' => $file->mime_type ?: 'application/pdf',
        ]];
    }
}
