<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Domain\Facades\SystemHealthFacade\SystemHealthFacade;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The system health page. Read-only — it renders figures computed on request
 * and probes dependencies with GETs; it writes nothing.
 */
final class SystemHealthController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('SystemHealth/index', SystemHealthFacade::overview());
    }
}
