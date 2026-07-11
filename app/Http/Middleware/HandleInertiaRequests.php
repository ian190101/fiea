<?php

namespace App\Http\Middleware;

use App\Models\SystemSetting;
use App\Models\SystemNotification;
use App\Services\BrandingAssetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $user?->loadMissing('roles.permissions');

        $brandingAssets = app(BrandingAssetService::class);

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'email' => $user->email,
                    'theme_preference' => $user->theme_preference,
                    'is_active' => $user->is_active,
                    'roles' => $user->roles
                        ->map(fn ($role) => [
                            'id' => $role->id,
                            'code' => $role->code,
                            'name' => $role->name,
                        ])
                        ->values()
                        ->all(),
                ] : null,
                'permissions' => $user?->permissionCodes() ?? [],
            ],
            'branding' => [
                'logoUrl' => $brandingAssets->logoUrl(),
                'primaryColor' => $brandingAssets->colors()['primary'],
                'secondaryColor' => $brandingAssets->colors()['secondary'],
                'accentColor' => $brandingAssets->colors()['accent'],
            ],
            'notifications' => fn () => $this->notifications($request),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'errors' => fn () => $request->session()->get('errors')
                    ? $request->session()->get('errors')->getBag('default')->getMessages()
                    : (object) [],
            ],
        ];
    }

    /**
     * @return array{unread: int, recent: array<int, array{id: int, title: string, severity: string, created_at: string|null}>}
     */
    private function notifications(Request $request): array
    {
        $user = $request->user();

        if (! $user || ! Schema::hasTable('system_notifications')) {
            return ['unread' => 0, 'recent' => []];
        }

        return $this->remember("fiea:notifications:shared:{$user->id}", 10, fn () => [
            'unread' => SystemNotification::query()
                ->where('user_id', $user->id)
                ->whereNull('read_at')
                ->count(),
            'recent' => SystemNotification::query()
                ->where('user_id', $user->id)
                ->whereNull('read_at')
                ->latest()
                ->limit(3)
                ->get(['id', 'title', 'severity', 'created_at'])
                ->map(fn (SystemNotification $notification) => [
                    'id' => $notification->id,
                    'title' => $notification->title,
                    'severity' => $notification->severity,
                    'created_at' => $notification->created_at?->toIso8601String(),
                ])
                ->all(),
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

        return Cache::remember($key, now()->addSeconds($seconds), $callback);
    }
}
