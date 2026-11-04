<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\ContactPerson;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;

final class InvoiceEmailContactQuery
{
    /** @return Builder<ContactPerson> */
    public function forInvoice(Invoice $invoice): Builder
    {
        $invoice->loadMissing('tripPhase.team');
        $phase = $invoice->tripPhase;

        return ContactPerson::query()->whereNotNull('email')->where('email', '!=', '')
            ->where(function (Builder $query) use ($invoice, $phase): void {
                $query->whereKey($invoice->contact_person_id)
                    ->orWhereHas('assignments', fn (Builder $assignment) => $assignment
                        ->where('is_active', true)
                        ->where(fn (Builder $scope) => $scope->where('team_id', $phase->team_id)
                            ->orWhere(fn (Builder $chapter) => $chapter->whereNull('team_id')
                                ->where('chapter_id', $phase->team->chapter_id))));
            });
    }
}
