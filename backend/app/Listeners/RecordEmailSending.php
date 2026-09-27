<?php

namespace App\Listeners;

use App\Services\Mail\EmailLogService;
use Illuminate\Mail\Events\MessageSending;

class RecordEmailSending
{
    public function __construct(private readonly EmailLogService $emails) {}

    public function handle(MessageSending $event): void
    {
        $this->emails->recordSending($event->message, $event->data);
    }
}
