<?php

namespace App\Services\DataSources\NepalStock;

/**
 * NEPSE's own live market-open flag — shared by every sync that shouldn't
 * write anything while the market's closed (a stray ping on a non-trading
 * day should never leave today's data wrong). Not just a weekday check —
 * this also catches public holidays the app has no calendar for, and any
 * ad-hoc closure.
 */
class NepalStockMarketStatusService
{
    private const MARKET_OPEN_PATH = '/api/nots/nepse-data/market-open';

    public function __construct(private readonly NepalStockClient $client) {}

    /**
     * Confirmed live to return e.g. {"isOpen":"OPEN",...}. Anything other
     * than exactly "OPEN" (closed, an unexpected value, a field NEPSE
     * renamed) is treated as closed — skipping a sync is harmless, writing
     * wrong data on a closed day isn't.
     */
    public function isOpen(): bool
    {
        $response = $this->client->get(self::MARKET_OPEN_PATH, timeout: 15);

        $response->throw();

        return strtoupper((string) $response->json('isOpen')) === 'OPEN';
    }
}
