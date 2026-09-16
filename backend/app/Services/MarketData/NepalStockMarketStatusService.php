<?php

namespace App\Services\MarketData;

use Illuminate\Support\Facades\Http;

/**
 * NEPSE's own live market-open flag — shared by every sync that shouldn't
 * write anything while the market's closed (a stray ping on a non-trading
 * day should never leave today's data wrong). Not just a weekday check —
 * this also catches public holidays the app has no calendar for, and any
 * ad-hoc closure.
 */
class NepalStockMarketStatusService
{
    private const BASE_URL = 'https://www.nepalstock.com';

    private const MARKET_OPEN_PATH = '/api/nots/nepse-data/market-open';

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome Safari';

    public function __construct(private readonly NepalStockTokenService $tokens) {}

    /**
     * Confirmed live to return e.g. {"isOpen":"OPEN",...}. Anything other
     * than exactly "OPEN" (closed, an unexpected value, a field NEPSE
     * renamed) is treated as closed — skipping a sync is harmless, writing
     * wrong data on a closed day isn't.
     */
    public function isOpen(): bool
    {
        $token = $this->tokens->getAccessToken();

        $response = Http::withHeaders([
            'User-Agent' => self::USER_AGENT,
            'Referer' => self::BASE_URL.'/',
            'Authorization' => 'Salter '.$token,
        ])->timeout(15)->get(self::BASE_URL.self::MARKET_OPEN_PATH);

        $response->throw();

        return strtoupper((string) $response->json('isOpen')) === 'OPEN';
    }
}
