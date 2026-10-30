<?php
declare(strict_types=1);
namespace App\Console\Commands;

use App\Jobs\SendInvoiceEmailJob;
use App\Models\OutboxEvent;
use App\Models\StorageFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class DispatchOutbox extends Command
{
    protected $signature = 'fiea:dispatch-outbox {--limit=100}';
    protected $description = 'Despacha eventos pendientes del outbox';

    public function handle(): int
    {
        $limit = min(1000, max(1, (int) $this->option('limit')));
        $ids = OutboxEvent::query()->where(function ($query): void {
            $query->where(fn ($q) => $q->where('status', 'pending')
                ->where('available_at', '<=', now()))
                ->orWhere(fn ($q) => $q->where('status', 'processing')
                    ->where('locked_until', '<=', now()));
        })->orderBy('id')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            $event = DB::transaction(function () use ($id): ?OutboxEvent {
                $event = OutboxEvent::query()->lockForUpdate()->find($id);
                if (!$event || $event->status === 'done'
                    || ($event->status === 'processing' && $event->locked_until?->isFuture())) return null;
                $event->forceFill(['status' => 'processing', 'locked_until' => now()->addSeconds(180),
                    'attempts' => $event->attempts + 1])->save();
                return $event;
            });
            if (!$event) continue;
            try {
                if ($event->type === 'invoice-email') {
                    Bus::dispatch((new SendInvoiceEmailJob((int) $event->aggregate_id,
                        outboxEventId: $event->id))->onConnection('database'));
                } elseif ($event->type === 'storage-delete') {
                    $file = StorageFile::query()->find($event->aggregate_id);
                    if ($file && $this->referenced($file->id)) {
                        $event->forceFill(['status' => 'pending', 'locked_until' => null,
                            'available_at' => now()->addDay()])->save();
                        continue;
                    }
                    if ($file) {
                        Storage::disk($file->diskName())->delete($file->object_key);
                        $file->delete();
                    }
                    $event->forceFill(['status' => 'done', 'locked_until' => null])->save();
                } else {
                    throw new \RuntimeException('Tipo de evento no soportado.');
                }
            } catch (\Throwable $exception) {
                report($exception);
                $event->forceFill(['status' => 'pending', 'locked_until' => null,
                    'available_at' => now()->addMinutes(5),
                    'error_reference' => (string) Str::uuid()])->save();
            }
        }
        return self::SUCCESS;
    }

    private function referenced(int $fileId): bool
    {
        foreach ([['receipts','storage_file_id'],['invoices','pdf_file_id'],
            ['trip_phases','draft_pdf_file_id'],['system_settings','logo_file_id'],
            ['backup_runs','storage_file_id']] as [$table,$column]) {
            if (DB::table($table)->where($column,$fileId)->exists()) return true;
        }
        return \App\Models\EmailLog::query()
            ->where('attachment_snapshot->storage_file_id',$fileId)->exists();
    }
}
