<?php

namespace App\Services;

use App\Models\EmailTemplate;
use App\Models\Invoice;

class InvoiceEmailTemplateService
{
    public function templateFor(Invoice $invoice): EmailTemplate
    {
        return EmailTemplate::query()
            ->where('invoice_type', $invoice->type)
            ->where('stage', $invoice->stage)
            ->where('is_active', true)
            ->first()
            ?? $this->fallbackTemplate($invoice);
    }

    /**
     * @return array{subject: string, body: string, template_id: int|null}
     */
    public function renderFor(Invoice $invoice): array
    {
        $invoice->loadMissing(['tripPhase.project', 'tripPhase.team']);
        $template = $this->templateFor($invoice);
        $tokens = $this->tokens($invoice);

        return [
            'subject' => strtr($template->subject_template, $tokens),
            'body' => strtr($template->body_template, $tokens),
            'template_id' => $template->exists ? $template->id : null,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function availableTokens(): array
    {
        return [
            '{{invoice_code}}' => 'Codigo de invoice',
            '{{invoice_type}}' => 'Tipo IC o MAT',
            '{{stage}}' => 'Initial o Final',
            '{{project_code}}' => 'Codigo del proyecto',
            '{{project_name}}' => 'Nombre del proyecto',
            '{{team_name}}' => 'Nombre del equipo',
            '{{grand_total}}' => 'Total general en USD',
            '{{total_dr}}' => 'Total DR en USD',
            '{{total_wodr}}' => 'Total WODR en USD',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function tokens(Invoice $invoice): array
    {
        return [
            '{{invoice_code}}' => (string) $invoice->code,
            '{{invoice_type}}' => (string) $invoice->type,
            '{{stage}}' => $invoice->stage === 'final' ? 'Final' : 'Initial',
            '{{project_code}}' => (string) ($invoice->tripPhase?->project?->code ?? ''),
            '{{project_name}}' => (string) ($invoice->tripPhase?->project?->name ?? ''),
            '{{team_name}}' => (string) ($invoice->tripPhase?->team?->name ?? ''),
            '{{grand_total}}' => $this->money($invoice->grand_total),
            '{{total_dr}}' => $this->money($invoice->total_dr),
            '{{total_wodr}}' => $this->money($invoice->total_wodr),
        ];
    }

    private function fallbackTemplate(Invoice $invoice): EmailTemplate
    {
        return new EmailTemplate([
            'name' => $invoice->type.' '.($invoice->stage === 'final' ? 'Final' : 'Initial').' invoice',
            'invoice_type' => $invoice->type,
            'stage' => $invoice->stage,
            'subject_template' => 'FIEA {{invoice_type}} {{stage}} Invoice - {{project_code}}',
            'body_template' => $this->defaultBody(),
            'is_active' => true,
        ]);
    }

    private function defaultBody(): string
    {
        return implode("\n", [
            'Hello,',
            '',
            'Please find the {{invoice_type}} {{stage}} invoice for {{project_code}} - {{project_name}} attached.',
            '',
            'Invoice code: {{invoice_code}}',
            'Grand total: {{grand_total}}',
            '',
            'Best regards,',
            'FIEA Team',
        ]);
    }

    private function money(mixed $value): string
    {
        return '$'.number_format((float) $value, 2);
    }
}
