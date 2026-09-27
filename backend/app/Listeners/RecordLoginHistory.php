<?php

namespace App\Listeners;

use App\Events\UserLoggedIn;
use App\Services\Auth\LoginHistoryService;

class RecordLoginHistory
{
    public function __construct(private readonly LoginHistoryService $history) {}

    public function handle(UserLoggedIn $event): void
    {
        $this->history->record($event->user, $event->ip, $event->userAgent);
    }
}
