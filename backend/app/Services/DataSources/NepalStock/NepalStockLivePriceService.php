<?php

namespace App\Services\DataSources\NepalStock;

use RuntimeException;

/**
 * NEPSE's live-market feed: every security's current session prices in one
 * JSON call. Only holds the CURRENT session — populated while the market is
 * open and for a while after the close, emptied overnight — so it's the
 * source for "now"; ShareSansar by date is the source for a finished day
 * (see DailyPriceSyncService).
 */
class NepalStockLivePriceService
{
    private const LIVE_MARKET_PATH = '/api/nots/lives-market';

    public function __construct(private readonly NepalStockClient $client) {}

    /**
     * The current session's prices, in DailyPriceWriter's row shape. Empty if
     * NEPSE has nothing live right now. Throws if the request fails.
     *
     * @return list<array{symbol: string, open: float, high: float, low: float, close: float, volume: int, turnover: float, company_name: ?string, nepse_security_id: ?int}>
     */
    public function currentPrices(): array
    {
        $response = $this->client->get(self::LIVE_MARKET_PATH);
        $response->throw();

        $rows = $response->json();

        if (! is_array($rows)) {
            throw new RuntimeException('nepalstock.com live-market returned something that isn\'t a list — the API may have changed.');
        }

        $prices = [];
        foreach ($rows as $row) {
            $symbol = strtoupper((string) ($row['symbol'] ?? ''));

            if ($symbol === '') {
                continue;
            }

            $prices[] = [
                'symbol' => $symbol,
                'open' => $this->number($row['openPrice'] ?? null),
                'high' => $this->number($row['highPrice'] ?? null),
                'low' => $this->number($row['lowPrice'] ?? null),
                // Mid-session this is the latest trade, not the final close —
                // the next closed-market sync replaces it with ShareSansar's final.
                'close' => $this->number($row['lastTradedPrice'] ?? null),
                'volume' => (int) $this->number($row['totalTradeQuantity'] ?? null),
                'turnover' => $this->number($row['totalTradeValue'] ?? null),
                'company_name' => $row['securityName'] ?? null,
                'nepse_security_id' => isset($row['securityId']) ? (int) $row['securityId'] : null,
            ];
        }

        return $prices;
    }

    private function number(mixed $raw): float
    {
        if ($raw === null) {
            return 0.0;
        }

        $clean = trim(str_replace([',', ' '], '', (string) $raw));

        return $clean === '' ? 0.0 : (float) $clean;
    }
}
