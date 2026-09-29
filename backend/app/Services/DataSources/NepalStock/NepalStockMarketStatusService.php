<?php

namespace App\Services\DataSources\NepalStock;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * NEPSE's own market status: whether it's open right now, and the date of
 * the latest trading session. Not just a weekday check — it also reflects
 * public holidays the app has no calendar for, and any ad-hoc closure.
 */
class NepalStockMarketStatusService
{
    private const MARKET_OPEN_PATH = '/api/nots/nepse-data/market-open';

    public function __construct(private readonly NepalStockClient $client) {}

    /**
     * Both values from one request.
     *
     * @return array{open: bool, last_open_date: string}
     */
    public function current(): array
    {
        $status = $this->fetch();

        return [
            'open' => $this->isOpenIn($status),
            'last_open_date' => $this->dateIn($status),
        ];
    }

    /**
     * Anything other than exactly "OPEN" (closed, an unexpected value, a
     * field NEPSE renamed) is treated as closed.
     */
    public function isOpen(): bool
    {
        return $this->isOpenIn($this->fetch());
    }

    /**
     * The date of the latest trading session (e.g. "2026-09-28", from NEPSE's
     * "asOf") — today's session while the market is open, and the most recent
     * one while it's closed. "" only if NEPSE sends no readable date.
     */
    public function lastOpenDate(): string
    {
        return $this->dateIn($this->fetch());
    }

    /**
     * e.g. {"isOpen":"CLOSE","asOf":"2026-09-28T15:00:00","id":80}. Throws if the request fails.
     */
    private function fetch(): array
    {
        $response = $this->client->get(self::MARKET_OPEN_PATH, timeout: 15);

        $response->throw();

        return (array) $response->json();
    }

    private function isOpenIn(array $status): bool
    {
        return strtoupper((string) ($status['isOpen'] ?? '')) === 'OPEN';
    }

    private function dateIn(array $status): string
    {
        if (empty($status['asOf'])) {
            return '';
        }

        try {
            return Carbon::parse($status['asOf'])->toDateString();
        } catch (Throwable) {
            return '';
        }
    }
}
