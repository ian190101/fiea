<?php

namespace App\Http\Controllers;

use App\Models\ContactAssignment;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ContactAssignmentController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        ContactAssignment::query()->create($this->validatedData($request));

        return back()->with('success', 'Asignacion creada correctamente.');
    }

    public function update(Request $request, ContactAssignment $assignment): RedirectResponse
    {
        $assignment->fill($this->validatedData($request))->save();

        return back()->with('success', 'Asignacion actualizada correctamente.');
    }

    public function destroy(ContactAssignment $assignment): RedirectResponse
    {
        $assignment->delete();

        return back()->with('success', 'Asignacion eliminada correctamente.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedData(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'contact_person_id' => ['required', 'integer', Rule::exists('contact_people', 'id')],
            'chapter_id' => ['nullable', 'integer', Rule::exists('chapters', 'id')],
            'team_id' => ['nullable', 'integer', Rule::exists('teams', 'id')],
            'role' => ['required', 'string', 'max:80'],
            'is_billing_contact' => ['required', 'boolean'],
            'is_email_recipient' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ]);

        $validator->after(function ($validator) use ($request) {
            if (!$request->filled('chapter_id') && !$request->filled('team_id')) {
                $validator->errors()->add('chapter_id', 'Selecciona un capitulo o un equipo.');
            }

            if (!$request->filled('team_id') || !$request->filled('chapter_id')) {
                return;
            }

            $team = Team::query()->find($request->integer('team_id'));
            if ($team && $team->chapter_id !== $request->integer('chapter_id')) {
                $validator->errors()->add('team_id', 'El equipo seleccionado no pertenece al capitulo indicado.');
            }
        });

        $data = $validator->validate();

        if (!empty($data['team_id']) && empty($data['chapter_id'])) {
            $data['chapter_id'] = Team::query()->whereKey($data['team_id'])->value('chapter_id');
        }

        return $data;
    }
}
