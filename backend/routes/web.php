<?php

use App\Http\Controllers\CronController;
use App\Services\Cron\CronSchedule;
use Illuminate\Support\Facades\Route;

// URL-triggered pipeline, driven by an external pinger instead of a server
// cron. One route per entry in CronSchedule::TASKS — see that class for the
// full list, what each does, and when to ping it. Every URL requires
// ?key=<CRON_SECRET> (VerifyCronSecret); with no secret set, all of them 403.
Route::middleware('cron.secret')->prefix('cron')->group(function () {
    foreach (CronSchedule::TASKS as $path => $entry) {
        Route::get($path, CronController::class)->defaults('task', $entry['task']);
    }
});
