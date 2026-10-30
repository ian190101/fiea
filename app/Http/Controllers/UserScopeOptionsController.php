<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Chapter;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UserScopeOptionsController extends Controller
{
    public function __invoke(Request $request, string $kind): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor?->is_active && $actor->scope_all && $actor->hasPermission('scope.manage'), 403);
        abort_unless(in_array($kind, ['projects', 'chapters'], true), 404);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'cursor' => ['nullable', 'string', 'max:500']]);
        $query = $kind === 'projects' ? Project::query() : Chapter::query();
        $search = trim($data['q'] ?? '');
        if ($search !== '') {
            $query->where(function ($q) use ($search, $kind): void {
                $q->where('name', 'like', '%'.$search.'%');
                if ($kind === 'projects') {
                    $q->orWhere('code', 'like', '%'.$search.'%');
                }
            });
        }

        return response()->json($query->orderBy('id')->cursorPaginate(25, ['id', 'name'], 'cursor', $data['cursor'] ?? null)->withQueryString());
    }
}
