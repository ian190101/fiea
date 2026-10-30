<?php

namespace App\Http\Controllers;

use App\Models\ContactAssignment;
use App\Models\Team;
use App\Http\Requests\SaveContactAssignmentRequest;
use App\Actions\Contacts\SaveContactAssignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ContactAssignmentController extends Controller
{
    public function store(SaveContactAssignmentRequest $request,
        SaveContactAssignment $action): RedirectResponse
    {
        $action->execute($request->user(),null,$request->validated());

        return back()->with('success', 'Asignacion creada correctamente.');
    }

    public function update(SaveContactAssignmentRequest $request, ContactAssignment $assignment,
        SaveContactAssignment $action): RedirectResponse
    {
        $action->execute($request->user(),$assignment,$request->validated());

        return back()->with('success', 'Asignacion actualizada correctamente.');
    }

    public function destroy(ContactAssignment $assignment): RedirectResponse
    {
        $this->authorizeDestination(request(), ['chapter_id' => $assignment->chapter_id]);
        $assignment->delete();

        return back()->with('success', 'Asignacion eliminada correctamente.');
    }

    private function authorizeDestination(Request $request, array $data): void
    {
        if ($request->user()->scope_all) return;
        abort_unless(DB::table('user_chapter_assignments')->where('user_id', $request->user()->id)
            ->where('chapter_id', $data['chapter_id'])->exists(), 404);
        if (isset($data['contact_person_id'])) {
            $assignments = ContactAssignment::query()->where('contact_person_id', $data['contact_person_id']);
            abort_unless((clone $assignments)->exists(), 404);
            abort_if($assignments->whereNotIn('chapter_id', DB::table('user_chapter_assignments')
                ->select('chapter_id')->where('user_id', $request->user()->id))->exists(), 404);
        }
    }

}
