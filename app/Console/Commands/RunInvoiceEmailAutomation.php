<?php

namespace App\Console\Commands;

use App\Services\InvoiceEmailAutomationService;
use Illuminate\Console\Command;

class RunInvoiceEmailAutomation extends Command
{
    protected $signature = 'fiea:email-automation
        {--prepare : Preparar correos automaticos para invoices aprobadas}
        {--send-pending : Enviar correos automaticos pendientes}
        {--retry-failed : Reintentar correos fallidos vencidos}';

    protected $description = 'Prepara, envia y reintenta correos automaticos de invoices.';

    public function handle(InvoiceEmailAutomationService $automation): int
    {
        $specificOptions = $this->option('prepare') || $this->option('send-pending') || $this->option('retry-failed');
        $summary = $automation->run(
            prepare: $specificOptions ? (bool) $this->option('prepare') : true,
            sendPending: $specificOptions ? (bool) $this->option('send-pending') : true,
            retryFailed: $specificOptions ? (bool) $this->option('retry-failed') : true,
        );

        $this->info(sprintf(
            'Automatizacion completada: %d preparados, %d encolados, %d reintentados, %d fallidos.',
            $summary['prepared'],
            $summary['sent'],
            $summary['retried'],
            $summary['failed'],
        ));

        return self::SUCCESS;
    }
}
