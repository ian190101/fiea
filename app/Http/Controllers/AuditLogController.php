<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'module' => ['nullable', 'string', 'max:80'],
            'action' => ['nullable', 'string', 'max:120'],
            'user_id' => ['nullable', 'integer'],
        ]);

        return Inertia::render('AuditLogs/Index', [
            'auditLogs' => Inertia::defer(fn () => $this->auditLogs($filters), 'audit-logs'),
            'filters' => [
                'module' => $filters['module'] ?? '',
                'action' => $filters['action'] ?? '',
                'user_id' => $filters['user_id'] ?? '',
            ],
            'filterOptions' => Inertia::defer(fn () => $this->filterOptions(), 'audit-logs'),
            'summary' => Inertia::defer(fn () => $this->summary(), 'audit-logs'),
        ]);
    }

    private function auditLogs(array $filters)
    {
        return AuditLog::query()
            ->with('user:id,name,username')
            ->when($filters['module'] ?? null, fn ($query, $module) => $query->where('module', $module))
            ->when($filters['action'] ?? null, fn ($query, $action) => $query->where('action', $action))
            ->when($filters['user_id'] ?? null, fn ($query, $userId) => $query->where('user_id', $userId))
            ->orderByDesc('id')
            ->cursorPaginate(30)
            ->withQueryString();
    }

    /**
     * @return array<string, mixed>
     */
    private function filterOptions(): array
    {
        return $this->remember('filter-options', 60, fn () => [
            'modules' => AuditLog::query()->select('module')->distinct()->orderBy('module')->pluck('module'),
            'actions' => AuditLog::query()->select('action')->distinct()->orderBy('action')->pluck('action'),
            'users' => User::query()
                ->whereIn('id', AuditLog::query()->select('user_id')->whereNotNull('user_id'))
                ->orderBy('name')
                ->get(['id', 'name', 'username']),
        ]);
    }

    /**
     * @return array{total: int, today: int, accounting: int, anonymous: int}
     */
    private function summary(): array
    {
        return $this->remember('summary', 30, fn () => [
            'total' => AuditLog::query()->count(),
            'today' => AuditLog::query()->whereDate('created_at', now()->toDateString())->count(),
            'accounting' => AuditLog::query()->where('module', 'accounting')->count(),
            'anonymous' => AuditLog::query()->whereNull('user_id')->count(),
        ]);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function remember(string $key, int $seconds, callable $callback): mixed
    {
        if (app()->environment('testing')) {
            return $callback();
        }

        return Cache::remember("fiea:audit-logs:{$key}", now()->addSeconds($seconds), $callback);
    }
}
