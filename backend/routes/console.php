<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The market-data pipeline (sync, recalculate, sector/ML/signal
// maintenance) no longer runs on the Laravel scheduler/server cron — it's
// triggered by URL instead (see routes/web.php's `cron/*` group and
// CronController), so it works on hosting without real cron/SSH access.
