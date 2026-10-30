<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\EmailTemplate;
use App\Http\Requests\SaveEmailTemplateRequest;
use App\Actions\Email\SaveEmailTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmailTemplateController extends Controller
{
    public function store(SaveEmailTemplateRequest $request,
        SaveEmailTemplate $action): RedirectResponse
    {
        $action->execute($request->user(),null,$request->validated());

        return back()->with('success', 'Plantilla creada correctamente.');
    }

    public function update(SaveEmailTemplateRequest $request, EmailTemplate $template,
        SaveEmailTemplate $action): RedirectResponse
    {
        $action->execute($request->user(),$template,$request->validated());

        return back()->with('success', 'Plantilla actualizada correctamente.');
    }

}
