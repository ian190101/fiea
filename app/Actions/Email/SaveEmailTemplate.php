<?php
declare(strict_types=1);
namespace App\Actions\Email;

use App\Models\AuditLog;
use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class SaveEmailTemplate
{
    public function execute(User $actor, ?EmailTemplate $template, array $data): EmailTemplate
    {
        return DB::transaction(function () use ($actor,$template,$data): EmailTemplate {
            $actor=User::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($actor->hasPermission('invoice_emails.manage'),403);
            if ($template) $template=EmailTemplate::query()->lockForUpdate()->findOrFail($template->id);
            $before=$template?->only(['name','invoice_type','stage','is_active']);
            $template ??= new EmailTemplate();
            $template->fill($data+['updated_by_id'=>$actor->id]);
            $template->updated_by_id=$actor->id;
            $template->save();
            AuditLog::query()->create([
                'user_id'=>$actor->id,'action'=>$before?'email_template_updated':'email_template_created',
                'module'=>'email_templates','auditable_type'=>EmailTemplate::class,
                'auditable_id'=>$template->id,
                'metadata'=>['before'=>$before,
                    'after'=>$template->only(['name','invoice_type','stage','is_active'])],
            ]);
            return $template;
        },3);
    }
}
