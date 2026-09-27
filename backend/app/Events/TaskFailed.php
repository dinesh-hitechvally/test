<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A URL-triggered cron step failed — see AlertTaskFailure for who gets told. */
class TaskFailed
{
    use Dispatchable;

    public function __construct(
        public readonly string $job,
        public readonly string $detail,
    ) {}
}
