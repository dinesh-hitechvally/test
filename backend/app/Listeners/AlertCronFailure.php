<?php

namespace App\Listeners;

use App\Events\CronTaskFailed;
use App\Services\CronAlertService;

class AlertCronFailure
{
    public function __construct(private readonly CronAlertService $alerts) {}

    public function handle(CronTaskFailed $event): void
    {
        $this->alerts->notifyFailure($event->job, $event->detail);
    }
}
