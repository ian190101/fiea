<?php

namespace App\Console\Commands;

use App\Services\OperationHealthService;
use Illuminate\Console\Command;

class CheckProductionReadiness extends Command
{
    protected $signature = 'fiea:production-check
        {--json : Imprimir el resultado como JSON}
        {--fail-on-warning : Retornar error tambien cuando existan advertencias}';

    protected $description = 'Revisa si el sistema esta listo para operar o desplegar en produccion.';

    public function handle(OperationHealthService $health): int
    {
        $status = $health->status();

        if ($this->option('json')) {
            $this->line(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->renderTable($status);
        }

        if ($status['overall'] === 'failed') {
            return self::FAILURE;
        }

        if ($status['overall'] === 'warning' && $this->option('fail-on-warning')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param array{overall: string, checked_at: string, checks: array<int, array{name: string, status: string, detail: string, meta: array<string, mixed>}>} $status
     */
    private function renderTable(array $status): void
    {
        $this->info('Revision operativa FIEA');
        $this->line('Estado general: '.$this->label($status['overall']));
        $this->line('Fecha: '.$status['checked_at']);
        $this->newLine();

        $this->table(
            ['Chequeo', 'Estado', 'Detalle'],
            collect($status['checks'])
                ->map(fn (array $check) => [
                    $check['name'],
                    $this->label($check['status']),
                    $check['detail'],
                ])
                ->all()
        );
    }

    private function label(string $status): string
    {
        return match ($status) {
            'ok' => 'OK',
            'warning' => 'ATENCION',
            'failed' => 'FALLO',
            default => strtoupper($status),
        };
    }
}
