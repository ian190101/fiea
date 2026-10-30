<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class AuditWriter
{
    private const ALLOWED = [
        'project_saved' => ['code', 'name'],
        'project_deleted' => ['code', 'name'],
        'catalog_saved' => ['name', 'description', 'country_id', 'fund_type', 'service_fee_percentage'],
        'catalog_deleted' => ['name', 'description', 'country_id', 'fund_type', 'service_fee_percentage'],
        'invoice_approved' => ['status', 'version', 'snapshot_hash'],
        'invoice_voided' => ['status', 'version', 'reason'],
        'treasury_payment_recorded' => ['amount', 'direction', 'leg', 'version'],
        'credit_applied' => ['amount', 'balance', 'version'],
        'receipt_updated' => ['receipt_number', 'issued_on', 'amount', 'version'],
        'receipt_stored' => ['receipt_number', 'issued_on', 'amount', 'version'],
        'receipt_deleted' => ['version'],
        'accounting_review_updated' => ['accounting_total', 'version', 'note'],
    ];

    public function record(User $actor, string $action, Model $subject, ?int $tripPhaseId,
        array $before, array $after, string $correlationId): void
    {
        $allowed = self::ALLOWED[$action] ?? ['version', 'operation_id', 'import_id', 'reason'];
        $sanitize = static fn (array $values): array => collect($values)
            ->only($allowed)
            ->map(fn ($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d')
                : (is_scalar($value) || $value === null ? ($value === null ? null : (string) $value) : null))
            ->all();
        AuditLog::query()->create([
            'user_id' => $actor->id, 'action' => $action,
            'module' => Str::before($action, '_'), 'auditable_type' => $subject::class,
            'auditable_id' => $subject->getKey(), 'trip_phase_id' => $tripPhaseId,
            'correlation_id' => $correlationId, 'metadata' => [
                'before' => $sanitize($before), 'after' => $sanitize($after),
            ],
        ]);
    }
}
