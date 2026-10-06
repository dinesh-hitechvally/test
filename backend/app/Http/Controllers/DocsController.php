<?php

namespace App\Http\Controllers;

use App\Services\Docs\ApiDocsService;
use App\Services\Docs\CronDocsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * /docs — one page of API, trading-rule and cron documentation, topics on the
 * left and content on the right. The API and cron sections are built from the
 * live schema and routes (ApiDocsService / CronDocsService), so they stay
 * correct as the code changes.
 */
class DocsController extends Controller
{
    public function __invoke(Request $request): View
    {
        abort_unless(config('docs.enabled'), 404);

        $api = app(ApiDocsService::class);

        return view('docs.index', [
            'baseUrl' => $request->getSchemeAndHttpHost().$request->getBasePath(),
            'groups' => $api->operations(),
            'types' => $api->types(),
            'cron' => app(CronDocsService::class)->grouped(),
            't' => config('trading'),
        ]);
    }
}
