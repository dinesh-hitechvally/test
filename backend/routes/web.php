<?php

use App\Http\Controllers\CronController;
use App\Http\Cron\CronSchedule;
use Illuminate\Support\Facades\Route;

// URL-triggered tasks, driven by an external pinger instead of a server
// cron. One route per entry in config/cron.php — see that file for the full
// list, what each runs, and when to ping it. Every URL requires
// ?key=<CRON_SECRET> (VerifyCronSecret); with no secret set, all of them 403.
Route::middleware('cron.secret')->prefix('cron')->group(function () {
    foreach (CronSchedule::tasks() as $path => $entry) {
        Route::get($path, CronController::class)->defaults('task', $entry['task']);
    }
});
