<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Domain\Facades\DashboardFacade\DashboardFacade;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The overview page. Read-only — it renders figures computed on request and
 * writes nothing.
 */
final class DashboardController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('dashboard', DashboardFacade::overview());
    }
}
