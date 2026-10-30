<?php
declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

final class FinancialCatalogSeeder extends Seeder
{
    public function run(): void
    {
        // El catálogo legado conserva los documentos; los cálculos nuevos usan BudgetCatalog.
        ExpenseCategory::query()->where('name', 'Contingency')->where('fund_type', 'WODR')
            ->update(['fund_type' => 'DR']);
    }
}
