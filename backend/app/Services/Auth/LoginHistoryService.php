<?php

namespace App\Services\Auth;

use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Support\Collection;
use Throwable;

/** The Settings → Login History list: where and from what each login came. */
class LoginHistoryService
{
    public function __construct(private readonly LoginGeolocationService $geolocation) {}

    /**
     * Never throws: a failed geolocation lookup or DB write must never fail
     * the login itself — this is record-keeping, not part of the auth flow.
     * Location is best-effort (see LoginGeolocationService).
     */
    public function record(User $user, ?string $ip, ?string $userAgent): void
    {
        try {
            $location = $this->geolocation->locate($ip);

            LoginHistory::create([
                'user_id' => $user->id,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'city' => $location['city'],
                'region' => $location['region'],
                'country' => $location['country'],
                'logged_in_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** The user's last 50 logins, newest first. */
    public function recent(User $user): Collection
    {
        return $user->loginHistories()
            ->orderByDesc('logged_in_at')
            ->limit(50)
            ->get(['id', 'ip_address', 'user_agent', 'city', 'region', 'country', 'logged_in_at']);
    }
}
