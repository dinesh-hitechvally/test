<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A URL-triggered cron step failed — see AlertCronFailure for who gets told. */
class CronTaskFailed
{
    use Dispatchable;

    public function __construct(
        public readonly string $job,
        public readonly string $detail,
    ) {}
}
