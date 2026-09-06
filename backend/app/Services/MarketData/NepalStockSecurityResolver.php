<?php

namespace App\Services\MarketData;

use App\Models\Stock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Resolves a stock's nepalstock.com internal numeric security ID, needed by
 * every official-API endpoint that's scoped to one security. Shared by
 * NepalStockHistoryService and NepalStockCorporateActionsService so the
 * securities-list cache key and matching logic can't drift between them.
 */
class NepalStockSecurityResolver
{
    private const BASE_URL = 'https://www.nepalstock.com';

    private const SECURITIES_PATH = '/api/nots/security?nonDelisted=true';

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome Safari';

    public function __construct(private readonly NepalStockTokenService $tokens) {}

    public function resolve(Stock $stock): int
    {
        if ($stock->nepse_security_id !== null) {
            return $stock->nepse_security_id;
        }

        $securities = Cache::remember('nepse_securities_list', now()->addHours(6), function () {
            $token = $this->tokens->getAccessToken();

            $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Referer' => self::BASE_URL.'/',
                'Authorization' => 'Salter '.$token,
            ])->timeout(20)->get(self::BASE_URL.self::SECURITIES_PATH);

            $response->throw();

            return $response->json();
        });

        foreach ($securities as $security) {
            if (strtoupper((string) ($security['symbol'] ?? '')) === $stock->symbol) {
                $id = (int) $security['id'];
                $stock->update(['nepse_security_id' => $id]);

                return $id;
            }
        }

        throw new RuntimeException("Could not find [{$stock->symbol}] in nepalstock.com's securities list.");
    }
}
