<?php

namespace App\Jobs;

use App\Models\EmailLog;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Services\InvoiceEmailDeliveryService;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class SendInvoiceEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 60;

    public function __construct(
        private readonly int $emailLogId,
        private readonly ?int $userId = null,
        private readonly ?string $ipAddress = null,
        private readonly ?string $userAgent = null,
        private readonly ?int $outboxEventId = null,
    ) {
        $this->onQueue('emails');
    }

    public function handle(InvoiceEmailDeliveryService $delivery): void
    {
        if ($this->outboxEventId === null) {
            return;
        }
        $event = OutboxEvent::query()->find($this->outboxEventId);
        if (! $event || $event->status === 'done' || $event->type !== 'invoice-email') {
            return;
        }
        $emailLog = EmailLog::query()
            ->with('invoice')
            ->find($this->emailLogId);
        if (! $emailLog || ! $emailLog->delivery_key) {
            return;
        }
        $lock = Cache::lock('invoice-email:'.$emailLog->delivery_key, 180);
        if (! $lock->get()) {
            return;
        }
        try {
            $emailLog->refresh();
            if (! in_array($emailLog->status, ['sent', 'unknown'], true) && $emailLog->dispatch_deadline && now()->greaterThan($emailLog->dispatch_deadline)) {
                $emailLog->forceFill(['status' => $emailLog->last_attempted_at && $emailLog->status === 'queued' ? 'unknown' : 'expired', 'next_retry_at' => null])->save();
                $event->forceFill(['status' => 'done', 'locked_until' => null])->save();

                return;
            }
            if (! in_array($emailLog->status, ['sent', 'unknown'], true)) {
                $user = $this->userId ? User::query()->find($this->userId) : null;
                $delivery->send($emailLog, $user, $this->ipAddress, $this->userAgent);
                $emailLog->refresh();
            }
            if (in_array($emailLog->status, ['sent', 'unknown', 'failed', 'expired'], true)) {
                $event->forceFill(['status' => 'done', 'locked_until' => null])->save();
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function retryUntil(): DateTimeInterface
    {
        return EmailLog::query()->find($this->emailLogId)?->dispatch_deadline ?? now();
    }
}
