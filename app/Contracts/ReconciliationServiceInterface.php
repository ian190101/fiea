<?php
declare(strict_types=1);
namespace App\Contracts;
use App\Data\Financial\ReconciliationInput;
use App\Data\Financial\ReconciliationTotals;
interface ReconciliationServiceInterface
{
    public function reconcile(ReconciliationInput $input): ReconciliationTotals;
}
