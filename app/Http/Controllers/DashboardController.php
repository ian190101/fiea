<?php
declare(strict_types=1);
namespace App\Http\Controllers;

use App\Queries\DashboardQuery;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    public function __invoke(DashboardQuery $query): Response
    {
        return Inertia::render('Dashboard',$query->for(request()->user()));
    }
}
