<?php

namespace App\Mail;

use App\Models\EmailLog;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class InvoicePreparedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(private readonly EmailLog $emailLog)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->emailLog->subject);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.invoice-prepared', with: [
            'body' => $this->emailLog->body,
            'invoice' => $this->emailLog->invoice,
        ]);
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $file = $this->emailLog->invoice?->pdfFile;

        if (! $file || ! Storage::disk($file->diskName())->exists($file->object_key)) {
            return [];
        }

        return [
            Attachment::fromStorageDisk($file->diskName(), $file->object_key)
                ->as($file->original_name)
                ->withMime($file->mime_type ?: 'application/pdf'),
        ];
    }
}
