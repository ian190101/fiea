<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ActualExpense;
use App\Models\BudgetLine;
use App\Models\Chapter;
use App\Models\ChapterType;
use App\Models\Community;
use App\Models\Country;
use App\Models\EstimatedExpense;
use App\Models\Project;
use App\Models\Team;
use App\Models\TripPhase;
use App\Models\University;
use App\Models\User;
use App\Queries\AuditLogQuery;
use App\Services\Security\ScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class OptionLookupController extends Controller
{
    public function __invoke(Request $request, string $kind, ScopeService $scope): JsonResponse
    {
        $definitions = [
            'countries' => [Country::class, 'name', ['projects', 'catalogs']],
            'communities' => [Community::class, 'name', ['projects', 'catalogs']],
            'chapter-types' => [ChapterType::class, 'name', ['chapters', 'catalogs']],
            'universities' => [University::class, 'name', ['chapters', 'catalogs']],
            'projects' => [Project::class, 'name', ['trip_phases', 'projects']],
            'teams' => [Team::class, 'name', ['trip_phases', 'chapters', 'contacts']],
            'chapters' => [Chapter::class, 'name', ['chapters', 'contacts']],
            'technicians' => [User::class, 'name', ['trip_phases']],
            'trip-phases' => [TripPhase::class, 'phase', ['budgets', 'actual_expenses', 'invoices']],
            'estimated-expenses' => [EstimatedExpense::class, 'description', ['actual_expenses']],
            'actual-expenses' => [ActualExpense::class, 'description', ['receipts']],
            'budget-lines' => [BudgetLine::class, 'description_en', ['receipts']],
            'audit-users' => [User::class, 'name', ['audit_logs']],
        ];
        abort_unless(isset($definitions[$kind]), 404);
        [$class,$label,$modules] = $definitions[$kind];
        $actor = $request->user();
        abort_unless($actor?->is_active && $actor->hasAnyPermission(collect($modules)->flatMap(fn ($m) => [$m.'.view', $m.'.manage'])->all()), 403);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'cursor' => ['nullable', 'string', 'max:500'], 'selected_id' => ['nullable', 'integer', 'min:1'],
            'country_id' => ['nullable', 'integer', 'min:1'], 'trip_phase_id' => ['nullable', 'integer', 'min:1'], 'chapter_id' => ['nullable', 'integer', 'min:1']]);
        $query = $class::query();
        $phases = $scope->tripPhases($actor);
        $chapters = DB::table('user_chapter_assignments')->select('chapter_id')->where('user_id', $actor->id);
        if ($kind === 'projects' && ! $actor->scope_all) {
            $query->where(fn ($q) => $q
                ->whereIn('id', DB::table('user_project_assignments')->select('project_id')->where('user_id', $actor->id))
                ->orWhereIn('id', (clone $phases)->select('project_id')));
        }
        if ($kind === 'teams' && ! $actor->scope_all) {
            $query->where(fn ($q) => $q->whereIn('chapter_id', clone $chapters)
                ->orWhereIn('id', (clone $phases)->select('team_id')));
        }
        if ($kind === 'chapters' && ! $actor->scope_all) {
            $query->whereIn('id', clone $chapters);
        }
        if ($kind === 'technicians') {
            $query->where('is_active', true)->whereHas('roles', fn ($q) => $q->whereIn('code', ['tecnico', 'superadmin']));
            if (! ($actor->scope_all && $actor->hasPermission('trip_phases.manage'))) {
                $query->where(fn ($q) => $q->whereKey($actor->id)
                    ->orWhereIn('id', (clone $phases)->select('assigned_technician_id')));
            }
        }
        if ($kind === 'audit-users') {
            $query->whereIn('id', app(AuditLogQuery::class)->visible($actor)->select('user_id')->whereNotNull('user_id'));
        }
        if ($kind === 'trip-phases') {
            $query = $phases;
        }
        if (in_array($kind, ['estimated-expenses', 'actual-expenses'], true)) {
            $query = $scope->constrain($query, $actor, $query->getModel()->getTable().'.trip_phase_id');
        }
        if ($kind === 'budget-lines') {
            $query->whereHas('billingCase', fn ($q) => $q->whereIn('trip_phase_id', (clone $phases)->select('trip_phases.id')))
                ->whereHas('versions', fn ($q) => $q->where('stage', 'final')->where('is_captured', true));
        }
        if ($kind === 'communities' && isset($data['country_id'])) {
            $query->where('country_id', $data['country_id']);
        }
        if ($kind === 'teams' && isset($data['chapter_id'])) {
            $query->where('chapter_id', $data['chapter_id']);
        }
        if (in_array($kind, ['actual-expenses', 'estimated-expenses'], true) && isset($data['trip_phase_id'])) {
            $query->where('trip_phase_id', $data['trip_phase_id']);
        }
        if (trim($data['q'] ?? '') !== '') {
            $query->where(function ($q) use ($label, $data, $kind): void {
                $q->where($label, 'like', '%'.$data['q'].'%');
                if ($kind === 'projects') {
                    $q->orWhere('code', 'like', '%'.$data['q'].'%');
                }
            });
        }
        if (isset($data['selected_id'])) {
            $query->whereKey($data['selected_id']);
        }
        $columns = match ($kind) {
            'technicians','audit-users' => ['id', 'name', 'username'],
            'countries','chapter-types','universities' => ['id', 'name'],
            'chapters' => ['id', 'name', 'chapter_type_id', 'university_id'],
            'projects' => ['id', 'name', 'code', 'country_id', 'community_id'],
            'teams' => ['id', 'name', 'chapter_id'],
            'communities' => ['id', 'name', 'country_id'],
            'trip-phases' => ['id', 'phase', 'project_id', 'team_id', 'starts_on', 'ends_on', 'version', 'status'],
            'actual-expenses' => ['id', 'trip_phase_id', 'description', 'real_total', 'receipt_number'],
            'estimated-expenses' => ['id', 'trip_phase_id', 'description', 'expense_category_id', 'unit', 'initial_unit_cost', 'initial_quantity', 'fund_type'],
            'budget-lines' => ['id', 'billing_case_id', 'description_en', 'description_es'],
        };
        if ($kind === 'trip-phases') {
            $query->with(['project:id,code,name', 'team:id,name']);
        }
        if ($kind === 'actual-expenses') {
            $query->with(['tripPhase:id,project_id,version', 'tripPhase.project:id,code,name']);
        }
        if ($kind === 'budget-lines') {
            $query->with(['billingCase:id,trip_phase_id', 'billingCase.tripPhase:id,project_id,version', 'billingCase.tripPhase.project:id,code,name']);
        }

        return response()->json($query->orderBy('id')->cursorPaginate(25, $columns, 'cursor', $data['cursor'] ?? null)->withQueryString()
            ->through(function ($row) use ($kind, $label): array {
                $result = $row->toArray();
                $result['name'] = $kind === 'trip-phases' ? ($row->project?->code.' · '.$row->phase.' · '.$row->starts_on?->toDateString()) : $row->{$label};

                return $result;
            }));
    }
}
