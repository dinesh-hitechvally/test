<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Resolves a rough city/region/country for an IP via ip-api.com's free tier
 * (no API key, ~45 requests/min). Never throws — a failed or rate-limited
 * lookup just means the login history row gets saved with no location,
 * which is far better than blocking a login over a third-party service.
 */
class LoginGeolocationService
{
    private const ENDPOINT = 'http://ip-api.com/json/%s';

    /**
     * @return array{city: ?string, region: ?string, country: ?string}
     */
    public function locate(string $ip): array
    {
        $empty = ['city' => null, 'region' => null, 'country' => null];

        // Private/loopback IPs (every local-dev login) aren't real locations
        // — ip-api.com would just reject them as "private range", so this
        // skips the network call entirely rather than treating that as a
        // failure worth logging.
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $empty;
        }

        try {
            $response = Http::timeout(3)->get(sprintf(self::ENDPOINT, $ip), [
                'fields' => 'status,country,regionName,city',
            ]);

            $data = $response->json();

            if (($data['status'] ?? null) !== 'success') {
                return $empty;
            }

            return [
                'city' => $data['city'] ?? null,
                'region' => $data['regionName'] ?? null,
                'country' => $data['country'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::warning('IP geolocation lookup failed', ['ip' => $ip, 'error' => $e->getMessage()]);

            return $empty;
        }
    }
}
