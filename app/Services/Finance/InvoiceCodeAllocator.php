<?php
declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\BillingCase;
use App\Models\BillingCaseCode;
use App\Models\Invoice;
use App\Models\InvoiceSequence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class InvoiceCodeAllocator
{
    public function allocate(BillingCase $case, string $stage): string
    {
        abort_unless(in_array($stage, ['initial', 'final'], true), 422);
        $date = $case->metadata['document_date'] ?? null;
        abort_unless(is_string($date), 422, 'Falta fecha documental.');
        $parsedDate = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        $year = $parsedDate === false || $parsedDate->toDateString() !== $date ? null : $parsedDate->year;
        abort_unless($year !== null, 422, 'Fecha documental inválida.');

        $alias = $case->current_code_id !== null
            ? BillingCaseCode::query()->whereKey($case->current_code_id)
                ->where('billing_case_id', $case->id)->lockForUpdate()->first()
            : null;
        $suffix = $stage === 'final' ? '-F' : '';
        if ($alias && $alias->year === $year
            && !Invoice::query()->where('code', $alias->code.$suffix)->exists()) {
            return $alias->code.$suffix;
        }

        $series = 'IE';
        $shortYear = sprintf('%02d', $year % 100);
        $prefix = "{$series}-{$shortYear}-";
        DB::table('invoice_sequences')->insertOrIgnore([
            'series' => $series, 'year' => $year,
            'last_number' => $this->historicalMaximum($prefix),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $sequence = InvoiceSequence::query()->where('series', $series)->where('year', $year)
            ->lockForUpdate()->firstOrFail();
        do {
            $sequence->last_number++;
            $base = $prefix.sprintf('%03d', $sequence->last_number);
        } while (BillingCaseCode::query()->where('code', $base)->exists()
            || Invoice::query()->whereIn('code', [$base, $base.'-F'])->exists());
        $sequence->save();
        $alias = BillingCaseCode::query()->create([
            'billing_case_id' => $case->id, 'code' => $base, 'year' => $year,
        ]);
        $case->forceFill(['current_code_id' => $alias->id])->save();
        return $base.$suffix;
    }

    private function historicalMaximum(string $prefix): int
    {
        $codes = BillingCaseCode::query()->where('code', 'like', $prefix.'%')->pluck('code')
            ->merge(Invoice::query()->where('code', 'like', $prefix.'%')->pluck('code'));
        $max = 0;
        foreach ($codes as $code) {
            if (preg_match('/^'.preg_quote($prefix, '/').'([0-9]{3,})(?:-F)?$/D', (string) $code, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }
        return $max;
    }
}
