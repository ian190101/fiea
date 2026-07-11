<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\EmailTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmailTemplateController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $template = EmailTemplate::query()->create([
            ...$data,
            'updated_by_id' => $request->user()?->id,
        ]);

        $this->audit($request, $template, 'email_template_created', null);

        return back()->with('success', 'Plantilla creada correctamente.');
    }

    public function update(Request $request, EmailTemplate $template): RedirectResponse
    {
        $data = $this->validated($request, $template);
        $before = $template->only([
            'name',
            'invoice_type',
            'stage',
            'subject_template',
            'body_template',
            'is_active',
        ]);

        $template->fill([
            ...$data,
            'updated_by_id' => $request->user()?->id,
        ])->save();

        $this->audit($request, $template, 'email_template_updated', $before);

        return back()->with('success', 'Plantilla actualizada correctamente.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?EmailTemplate $template = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'invoice_type' => ['required', 'string', Rule::in(['IC', 'MAT'])],
            'stage' => [
                'required',
                'string',
                Rule::in(['initial', 'final']),
                Rule::unique('email_templates', 'stage')
                    ->where('invoice_type', $request->input('invoice_type'))
                    ->ignore($template?->id),
            ],
            'subject_template' => ['required', 'string', 'max:255'],
            'body_template' => ['required', 'string', 'max:5000'],
            'is_active' => ['required', 'boolean'],
        ]);
    }

    private function audit(Request $request, EmailTemplate $template, string $action, ?array $before): void
    {
        AuditLog::query()->create([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'module' => 'email_templates',
            'auditable_type' => EmailTemplate::class,
            'auditable_id' => $template->id,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'metadata' => [
                'before' => $before,
                'after' => $template->only([
                    'name',
                    'invoice_type',
                    'stage',
                    'subject_template',
                    'body_template',
                    'is_active',
                ]),
            ],
        ]);
    }
}
