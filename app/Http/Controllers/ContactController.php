<?php

namespace App\Http\Controllers;

use App\Models\Chapter;
use App\Models\ContactAssignment;
use App\Models\ContactPerson;
use App\Models\Team;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Contacts/Index', [
            'contacts' => Inertia::defer(fn () => ContactPerson::query()
                ->withCount(['assignments', 'assignments as active_assignments_count' => fn ($query) => $query->where('is_active', true)])
                ->orderBy('full_name')
                ->get(['id', 'full_name', 'email', 'phone', 'physical_address']), 'contacts'),
            'assignments' => Inertia::defer(fn () => ContactAssignment::query()
                ->with([
                    'contactPerson:id,full_name,email',
                    'chapter:id,name',
                    'team:id,chapter_id,name',
                ])
                ->orderByDesc('is_active')
                ->orderBy('role')
                ->get(['id', 'contact_person_id', 'chapter_id', 'team_id', 'role', 'is_billing_contact', 'is_email_recipient', 'is_active']), 'contacts'),
            'chapters' => Inertia::defer(fn () => Chapter::query()
                ->orderBy('name')
                ->get(['id', 'name']), 'contacts'),
            'teams' => Inertia::defer(fn () => Team::query()
                ->with('chapter:id,name')
                ->orderBy('name')
                ->get(['id', 'chapter_id', 'name']), 'contacts'),
            'roleOptions' => [
                'Primary Contact',
                'Billing Contact',
                'Travel Lead',
                'Faculty Advisor',
                'Volunteer',
                'Other',
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        ContactPerson::query()->create($this->validatedData($request));

        return back()->with('success', 'Contacto creado correctamente.');
    }

    public function update(Request $request, ContactPerson $contact): RedirectResponse
    {
        $contact->fill($this->validatedData($request, $contact->id))->save();

        return back()->with('success', 'Contacto actualizado correctamente.');
    }

    public function destroy(ContactPerson $contact): RedirectResponse
    {
        try {
            $contact->delete();
        } catch (QueryException) {
            return back()->withErrors([
                'contact' => 'No se puede eliminar porque tiene asignaciones relacionadas.',
            ]);
        }

        return back()->with('success', 'Contacto eliminado correctamente.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedData(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'full_name' => ['required', 'string', 'max:160'],
            'email' => ['nullable', 'email:rfc', 'max:160', Rule::unique('contact_people', 'email')->ignore($id)],
            'phone' => ['nullable', 'string', 'max:60'],
            'physical_address' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
