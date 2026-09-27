<?php

namespace App\Listeners;

use App\Events\TaskFailed;
use App\Services\Alerts\FailureAlertService;

class AlertTaskFailure
{
    public function __construct(private readonly FailureAlertService $alerts) {}

    public function handle(TaskFailed $event): void
    {
        $this->alerts->notifyFailure($event->job, $event->detail);
    }
}
