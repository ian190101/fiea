<?php

namespace App\Http\Controllers;

use App\Services\OperationHealthService;
use Inertia\Inertia;
use Inertia\Response;

class OperationStatusController extends Controller
{
    public function __invoke(OperationHealthService $health): Response
    {
        return Inertia::render('Operations/Index', [
            'health' => Inertia::defer(fn () => $health->status(), 'operations'),
        ]);
    }
}
