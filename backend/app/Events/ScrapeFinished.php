<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * One fetch from an external data source finished, successfully or not.
 * Services announce the outcome; recording it (the Data Source Settings
 * page's scrape log) is RecordScrapeLog's job, not theirs.
 */
class ScrapeFinished
{
    use Dispatchable;

    public function __construct(
        public readonly string $source,
        public readonly bool $succeeded,
        public readonly int $recordsProcessed,
        public readonly string $message,
    ) {}
}
