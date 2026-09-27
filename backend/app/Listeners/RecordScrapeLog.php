<?php

namespace App\Listeners;

use App\Events\ScrapeFinished;
use App\Models\ScrapeLog;

class RecordScrapeLog
{
    public function handle(ScrapeFinished $event): void
    {
        ScrapeLog::create([
            'source' => $event->source,
            'status' => $event->succeeded ? 'success' : 'failed',
            'records_processed' => $event->recordsProcessed,
            'message' => $event->message,
        ]);
    }
}
