<?php

namespace App\Http\Controllers;

use App\Services\Docs\ApiDocsService;
use App\Services\Docs\CronDocsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * /console — a browser page for trying every GraphQL operation and cron
 * URL directly: log in, pick an operation, edit the query or variables, run it,
 * or run every query at once as a health check. The page only calls the same
 * public endpoints with the visitor's own token / cron key, so it grants nothing
 * extra. Switched off together with the docs (config/docs.php).
 */
class ApiConsoleController extends Controller
{
    public function __invoke(Request $request, ApiDocsService $api, CronDocsService $cron): View
    {
        abort_unless(config('docs.enabled'), 404);

        return view('docs.console', [
            'groups' => $api->operations(),
            'cron' => $cron->grouped(),
            // Includes a sub-folder install (https://host/sharemarket/public), which /graphql and /cron hang off.
            'baseUrl' => $request->getSchemeAndHttpHost().$request->getBasePath(),
        ]);
    }
}
