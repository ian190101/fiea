<?php
declare(strict_types=1);
namespace App\Services\Finance;
use App\Contracts\ReconciliationServiceInterface;
use App\Data\Financial\ReconciliationInput;
use App\Data\Financial\ReconciliationTotals;
use App\Support\Decimal as D;
final class ReconciliationService implements ReconciliationServiceInterface
{
    public function reconcile(ReconciliationInput $input): ReconciliationTotals
    {
        return new ReconciliationTotals(
            $input->operating === null ? null : D::money(D::sub($input->income, $input->operating)),
            $input->operating === null || $input->accounting === null
                ? null : D::money(D::sub($input->operating, $input->accounting)),
            $input->expectedFiea === null ? null : D::money(D::sub($input->expectedFiea, $input->receivedFiea)),
        );
    }
}
