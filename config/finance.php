<?php
declare(strict_types=1);

return [
    'calculation_version' => 'fiea-2026-01',
    'currency' => 'USD',
    'ic_arranged_rate' => '0.05',
    'mat_purchase_rate' => '0.15',
    'contingency_rate' => '0.10',
    'bank_fee_initial' => '80.00',
    'bank_fee_final' => '80.00',
    'control_import_locale' => null,
    'payment_terms' => [
        'lead_time' => 'El pago debe recibirse 14 días antes de la llegada.',
        'check' => 'Engineers in Action, 6910 E 14th St., Tulsa, OK 74112',
        'methods' => ['CHECK', 'ACH', 'CREDIT CARD (+3.5% fee)', 'PAYPAL (+2% fee)'],
    ],
];
