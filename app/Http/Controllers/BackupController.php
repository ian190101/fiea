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
        return Inertia::render('Backups/Index', [
            'backups' => Inertia::defer(fn () => BackupRun::query()
                ->with(['storageFile:id,object_key,original_name,mime_type,size_bytes,provider', 'createdBy:id,name,username'])
                ->orderByDesc('created_at')
                ->limit(100)
                ->get(), 'backups'),
        ]);
    }

    public function store(Request $request, DatabaseBackupService $backups): RedirectResponse
    {
        $backup = $backups->create($request->user());

        if ($backup->status === 'failed') {
            return back()->withErrors([
                'backup' => 'No se pudo generar el backup: '.$backup->error_message,
            ]);
        }

        return back()->with('success', 'Backup de base de datos generado correctamente.');
    }

    public function show(BackupRun $backup): StreamedResponse
    {
        $backup->load('storageFile');
        abort_unless($backup->storageFile instanceof StorageFile, 404);
        abort_unless($backup->status === 'completed', 404);

        $disk = $backup->storageFile->diskName();

        return Storage::disk($disk)->download(
            $backup->storageFile->object_key,
            $backup->storageFile->original_name,
            ['Content-Type' => $backup->storageFile->mime_type]
        );
    }
}
