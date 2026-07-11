<?php

namespace App\Http\Controllers;

use App\Models\SystemNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SystemNotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:unread,read'],
        ]);

        return Inertia::render('Notifications/Index', [
            'filters' => [
                'status' => $filters['status'] ?? null,
            ],
            'notifications' => Inertia::defer(fn () => SystemNotification::query()
                ->with('createdBy:id,name,username')
                ->where('user_id', $request->user()->id)
                ->when(($filters['status'] ?? null) === 'unread', fn ($query) => $query->whereNull('read_at'))
                ->when(($filters['status'] ?? null) === 'read', fn ($query) => $query->whereNotNull('read_at'))
                ->orderByDesc('created_at')
                ->cursorPaginate(20)
                ->withQueryString(), 'notifications'),
        ]);
    }

    public function markRead(Request $request, SystemNotification $notification): RedirectResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 404);

        $notification->forceFill([
            'read_at' => $notification->read_at ?? now(),
        ])->save();

        return back()->with('success', 'Notificacion marcada como leida.');
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        SystemNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return back()->with('success', 'Notificaciones marcadas como leidas.');
    }
}
