<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Cron\CronSchedule;
use App\Services\DataSources\ScrapeHealthService;

/**
 * The Data Source Settings page: the scheduled cron URLs (with CRON_SECRET
 * filled in, ready to paste into a pinger) plus any stocks flagged with a
 * failed fetch. Showing the real secret is safe here only because this
 * endpoint sits behind auth:sanctum — only a logged-in user ever sees it.
 */
class ScheduleController extends Controller
{
    public function index(CronSchedule $schedule, ScrapeHealthService $health)
    {
        return response()->json([
            'secret_configured' => filled(config('services.cron.secret')),
            'jobs' => $schedule->scheduled(),
            'flagged_stocks' => $health->flaggedStocks(),
        ]);
    }
}
