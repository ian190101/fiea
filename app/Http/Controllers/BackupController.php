<?php

namespace App\Http\Controllers;

use App\Models\BackupRun;
use App\Models\StorageFile;
use App\Services\DatabaseBackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupController extends Controller
{
    public function index(): Response
    {
        abort_unless(request()->user()->scope_all, 403);

        return Inertia::render('Backups/Index', [
            'backups' => Inertia::defer(fn () => BackupRun::query()
                ->with(['storageFile:id,original_name,mime_type,size_bytes', 'createdBy:id,name,username'])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->cursorPaginate(25), 'backups'),
        ]);
    }

    public function store(Request $request, DatabaseBackupService $backups): RedirectResponse
    {
        abort_unless($request->user()->scope_all, 403);
        $backup = $backups->create($request->user());

        if ($backup->status === 'failed') {
            return back()->withErrors([
                'backup' => 'No se pudo generar el backup. Referencia: '.$backup->error_message,
            ]);
        }

        return back()->with('success', 'Backup de base de datos generado correctamente.');
    }

    public function show(BackupRun $backup): StreamedResponse
    {
        $actor = request()->user();
        abort_unless($actor->scope_all && $actor->hasAnyPermission(['backups.view', 'backups.manage']), 403);
        $backup->load('storageFile');
        abort_unless($backup->storageFile instanceof StorageFile, 404);
        abort_unless($backup->status === 'completed', 404);

        $disk = $backup->storageFile->diskName();

        return Storage::disk($disk)->download(
            $backup->storageFile->object_key,
            mb_substr(str_replace(["\r", "\n", '\\', '/'], '_', $backup->storageFile->original_name), 0, 160),
            ['Content-Type' => $backup->storageFile->mime_type,
                'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']
        );
    }
}
