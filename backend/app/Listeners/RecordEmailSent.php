<?php

namespace App\Listeners;

use App\Services\Mail\EmailLogService;
use Illuminate\Mail\Events\MessageSent;

class RecordEmailSent
{
    public function __construct(private readonly EmailLogService $emails) {}

    public function handle(MessageSent $event): void
    {
        $this->emails->recordSent($event->sent);
    }
}
