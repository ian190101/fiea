<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\StorageFile;
use App\Services\TransactionalMailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class InvoiceDeliveryIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_reintento_conserva_clave_y_adjunto_congelado(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('invoices/1/document.pdf', '%PDF-1.4 contenido');
        $file = StorageFile::query()->create([
            'provider' => 'local', 'bucket' => null, 'object_key' => 'invoices/1/document.pdf',
            'original_name' => 'document.pdf', 'mime_type' => 'application/pdf',
            'size_bytes' => strlen('%PDF-1.4 contenido'), 'checksum' => hash('sha256', '%PDF-1.4 contenido'),
            'public_url' => null,
        ]);
        $invoice = new Invoice;
        $invoice->forceFill(['code' => 'IE-25-019', 'status' => 'approved']);
        $log = new EmailLog;
        $log->forceFill([
            'subject' => 'Documento aprobado', 'body' => 'Contenido preparado',
            'delivery_key' => '9898845b-2ed2-4312-bb71-a32b6e407888',
            'recipients_snapshot' => [['email' => 'a@example.test', 'recipient_type' => 'to']],
            'attachment_snapshot' => [
                'storage_file_id' => $file->id, 'checksum' => $file->checksum,
                'filename' => 'document.pdf', 'mime_type' => 'application/pdf',
            ],
            'retry_count' => 0,
        ]);
        $log->setRelation('invoice', $invoice);
        config()->set('services.resend.key', 'clave-de-prueba');
        config()->set('services.resend.endpoint', 'https://api.resend.com/emails');
        Http::fake(['api.resend.com/*' => Http::response(['id' => 'mensaje-1'], 200)]);
        $mail = app(TransactionalMailService::class);
        self::assertSame('mensaje-1', $mail->sendInvoice($log, ['a@example.test']));
        $log->retry_count = 3;
        self::assertSame('mensaje-1', $mail->sendInvoice($log, ['a@example.test']));
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key',
            'invoice-email-9898845b-2ed2-4312-bb71-a32b6e407888'));
    }
}
