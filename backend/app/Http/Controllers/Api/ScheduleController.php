<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DataSources\ScrapeHealthService;

/**
 * The Data Source Settings page: whether CRON_SECRET is set (every /cron/*
 * URL 403s without it) and which stocks are flagged with a failed fetch.
 * The cron URLs themselves are scheduled in cPanel, not in code.
 */
class ScheduleController extends Controller
{
    public function index(ScrapeHealthService $health)
    {
        return response()->json([
            'secret_configured' => filled(config('services.cron.secret')),
            'flagged_stocks' => $health->flaggedStocks(),
        ]);
    }
}
