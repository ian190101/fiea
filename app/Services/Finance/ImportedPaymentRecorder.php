<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Data\Financial\RecordPaymentData;
use App\Models\BillingCase;
use App\Models\TreasuryPayment;
use App\Models\User;
use App\Support\Decimal as D;
use Illuminate\Support\Str;

final class ImportedPaymentRecorder
{
    public function __construct(private readonly TreasuryService $treasury) {}

    public function record(User $actor, BillingCase $case, string $leg, mixed $stage, string $amount,
        mixed $date, mixed $reference, mixed $method, ?string $note): TreasuryPayment
    {
        abort_unless(in_array($stage, ['initial', 'final'], true) && is_string($date)
            && preg_match('/^(20\d{2})-(\d{2})-(\d{2})$/D', $date, $parts)
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            && is_string($reference) && trim($reference) !== '' && is_string($method), 422,
            'El cobro requiere etapa, fecha, método y referencia comprobados.');
        $reference = trim($reference);
        $method = trim($method);
        // La identidad del movimiento no depende del archivo ni del importe que se pretende corregir.
        $key = hash('sha256', implode('|', [$case->id, $leg, $reference, $date]));
        $prior = TreasuryPayment::query()->where('source_key', $key)->lockForUpdate()->first();
        if (! $prior) {
            $prior = TreasuryPayment::query()->where('billing_case_id', $case->id)->where('leg', $leg)
                ->where('direction', 'receipt')->where('reference', $reference)->whereDate('paid_on', $date)->lockForUpdate()->first();
        }
        if ($prior) {
            abort_unless($prior->amount === $amount && $prior->stage === $stage && $prior->method === $method, 409,
                'La referencia ya existe con otros datos. Registra un ajuste documentado en tesorería.');

            return $prior;
        }
        $case->refresh();
        $invoiceId = $stage === 'initial' ? $case->current_initial_id : $case->current_final_id;
        $payment = $this->treasury->record($actor, new RecordPaymentData((int) $case->id,
            $invoiceId === null ? null : (int) $invoiceId, $leg, $stage, 'receipt', D::money($amount),
            $date, $method, $reference, $note, (string) Str::uuid(), (int) $case->version));
        $payment->forceFill(['source_key' => $key])->save();
        $case->refresh();

        return $payment;
    }
}
