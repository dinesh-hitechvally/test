<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A user just logged in through the login form. Carries the request details
 * the listener needs, since the listener doesn't see the request itself.
 */
class UserLoggedIn
{
    use Dispatchable;

    public function __construct(
        public readonly User $user,
        public readonly ?string $ip,
        public readonly ?string $userAgent,
    ) {}
}
