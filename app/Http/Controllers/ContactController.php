<?php

namespace App\Http\Controllers;

use App\Models\Chapter;
use App\Models\ContactAssignment;
use App\Models\ContactPerson;
use App\Models\Team;
use App\Http\Requests\SaveContactRequest;
use App\Actions\Contacts\SaveContact;
use Illuminate\Support\Facades\DB;
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
        $actor = request()->user();
        $chapterIds = DB::table('user_chapter_assignments')->select('chapter_id')->where('user_id', $actor->id);
        return Inertia::render('Contacts/Index', [
            'contacts' => Inertia::defer(fn () => ContactPerson::query()
                ->when(!$actor->scope_all, fn ($q) => $q->whereHas('assignments', fn ($a) => $a->whereIn('chapter_id', $chapterIds)))
                ->withCount(['assignments', 'assignments as active_assignments_count' => fn ($query) => $query->where('is_active', true)])
                ->orderBy('full_name')
                ->orderBy('id')->cursorPaginate(25,
                    ['id', 'full_name', 'email', 'phone', 'physical_address'], 'contacts_cursor'), 'contacts'),
            'assignments' => Inertia::defer(fn () => ContactAssignment::query()
                ->when(!$actor->scope_all, fn ($q) => $q->whereIn('chapter_id', $chapterIds))
                ->with([
                    'contactPerson:id,full_name,email',
                    'chapter:id,name',
                    'team:id,chapter_id,name',
                ])
                ->orderByDesc('is_active')
                ->orderBy('role')
                ->orderBy('id')->cursorPaginate(25,
                    ['id', 'contact_person_id', 'chapter_id', 'team_id', 'role',
                        'is_billing_contact', 'is_email_recipient', 'is_active'], 'assignments_cursor'), 'contacts'),
            'chapters' => Inertia::defer(fn () => Chapter::query()
                ->when(!$actor->scope_all, fn ($q) => $q->whereIn('chapters.id', $chapterIds))
                ->orderBy('name')
                ->limit(25)->get(['id', 'name']), 'contacts'),
            'teams' => Inertia::defer(fn () => Team::query()
                ->when(!$actor->scope_all, fn ($q) => $q->whereIn('teams.chapter_id', $chapterIds))
                ->with('chapter:id,name')
                ->orderBy('name')
                ->limit(25)->get(['id', 'chapter_id', 'name']), 'contacts'),
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

    public function store(SaveContactRequest $request, SaveContact $action): RedirectResponse
    {
        $action->execute($request->user(),null,$request->validated());

        return back()->with('success', 'Contacto creado correctamente.');
    }

    public function update(SaveContactRequest $request, ContactPerson $contact,
        SaveContact $action): RedirectResponse
    {
        $action->execute($request->user(),$contact,$request->validated());

        return back()->with('success', 'Contacto actualizado correctamente.');
    }

    public function destroy(ContactPerson $contact): RedirectResponse
    {
        $this->authorizeContact(request(), $contact);
        try {
            $contact->delete();
        } catch (QueryException) {
            return back()->withErrors([
                'contact' => 'No se puede eliminar porque tiene asignaciones relacionadas.',
            ]);
        }

        return back()->with('success', 'Contacto eliminado correctamente.');
    }

    private function authorizeContact(Request $request, ContactPerson $contact): void
    {
        if ($request->user()->scope_all) return;
        $assignments = ContactAssignment::query()->where('contact_person_id', $contact->id);
        abort_unless((clone $assignments)->exists(), 404);
        $chapterIds = DB::table('user_chapter_assignments')->select('chapter_id')->where('user_id', $request->user()->id);
        abort_if($assignments->whereNotIn('chapter_id', $chapterIds)->exists(), 404);
    }

}
