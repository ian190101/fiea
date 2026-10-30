<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\AccountingReview;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Security\AccountingReviewScope;
use App\Services\Security\ScopeService;
use Illuminate\Database\Eloquent\Builder;

final class AuditLogQuery
{
    public function __construct(private readonly ScopeService $scope) {}

    public function for(User $actor, array $filters): array
    {
        abort_unless($actor->hasPermission('audit_logs.view'), 403);
        $base = $this->visible($actor);
        $logs = (clone $base)->with('user:id,name,username')
            ->when($filters['module'] ?? null, fn ($q, $v) => $q->where('module', $v))
            ->when($filters['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate(25)->withQueryString();

        return [
            'auditLogs' => $logs,
            'filterOptions' => [
                'modules' => (clone $base)->select('module')->distinct()->orderBy('module')->pluck('module'),
                'actions' => (clone $base)->select('action')->distinct()->orderBy('action')->pluck('action'),
                'users' => User::query()->whereIn('id', (clone $base)
                    ->select('user_id')->whereNotNull('user_id'))
                    ->orderBy('name')->limit(25)->get(['id', 'name', 'username']),
            ],
            'summary' => [
                'total' => (clone $base)->count(),
                'today' => (clone $base)->whereDate('created_at', now()->toDateString())->count(),
                'accounting' => (clone $base)->where('module', 'accounting')->count(),
                'anonymous' => (clone $base)->whereNull('user_id')->count(),
            ],
        ];
    }

    public function visible(User $actor): Builder
    {
        if (! $actor->scope_all) {
            $reviews = AccountingReview::query()->whereIn('project_id', $this->scope->tripPhases($actor)->select('project_id'))->get();
            $reviewIds = app(AccountingReviewScope::class)->visible($actor, $reviews)->pluck('id')->all();

            return AuditLog::query()->where(fn (Builder $q) => $q
                ->where(fn (Builder $regular) => $regular->where(fn ($q) => $q->whereNull('auditable_type')->orWhere('auditable_type', '!=', AccountingReview::class))
                    ->where(fn ($q) => $q->whereIn('trip_phase_id', $this->scope->tripPhases($actor)->select('trip_phases.id'))
                        ->orWhere(fn ($own) => $own->whereNull('trip_phase_id')->where('user_id', $actor->id))))
                ->orWhere(fn ($q) => $q->where('auditable_type', AccountingReview::class)->whereIn('auditable_id', $reviewIds)));
        }

        return AuditLog::query();
    }
}
