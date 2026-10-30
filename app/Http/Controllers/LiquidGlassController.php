<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateLiquidGlassRequest;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\LiquidGlassSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class LiquidGlassController extends Controller
{
    public function edit(Request $request, LiquidGlassSettings $settings): Response
    {
        abort_unless($request->user()?->hasRole('superadmin'), 403);

        return Inertia::render('Superadmin/LiquidGlass', [
            'profiles' => $settings->current(), 'defaults' => $settings->defaults(),
            'ranges' => config('liquid-glass.ranges'),
        ]);
    }

    public function update(UpdateLiquidGlassRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            $actor = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            abort_unless($actor->is_active && $actor->hasRole('superadmin') && $actor->hasPermission('superadmin.manage'), 403);
            $profiles = app(LiquidGlassSettings::class)->normalize($request->validated('profiles'));
            $settings = SystemSetting::query()->firstOrCreate(['id' => 1]);
            $settings->update(['liquid_glass' => $profiles, 'updated_by_id' => $actor->id]);
            AuditLog::create([
                'user_id' => $request->user()->id, 'action' => 'liquid_glass.updated', 'module' => 'settings',
                'auditable_type' => SystemSetting::class, 'auditable_id' => $settings->id,
                'ip_address' => $request->ip(), 'metadata' => ['profiles' => $profiles],
            ]);
        });
        Cache::forget(LiquidGlassSettings::CACHE_KEY);

        return back()->with('success', 'Efectos publicados. El diseño se aplicará también en el acceso y en las demás sesiones.');
    }
}
