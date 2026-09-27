<?php

namespace App\Services\DataSources\NepalStock;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The one place that knows how to talk to nepalstock.com's API: base URL,
 * the browser-like headers it insists on, and the "Salter <token>" auth.
 * Every NepalStock* service asks this for a path instead of repeating that
 * plumbing — so if NEPSE changes how requests must look, it's one edit here.
 *
 * A fresh token is minted for every request on purpose: tokens are only
 * valid ~45 seconds, so reusing one across a long paginated fetch fails
 * partway through.
 */
class NepalStockClient
{
    public const BASE_URL = 'https://www.nepalstock.com';

    public const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome Safari';

    public function __construct(private readonly NepalStockTokenService $tokens) {}

    /** Authenticated GET. The caller decides what counts as failure (->throw(), ->failed(), ...). */
    public function get(string $path, array $query = [], int $timeout = 20): Response
    {
        $request = Http::withHeaders([
            'User-Agent' => self::USER_AGENT,
            'Referer' => self::BASE_URL.'/',
            'Authorization' => 'Salter '.$this->tokens->getAccessToken(),
        ])->timeout($timeout);

        // Only pass a query array when there is one: an empty array would
        // REPLACE a query string already in $path (e.g. "?nonDelisted=true").
        return $query === []
            ? $request->get(self::BASE_URL.$path)
            : $request->get(self::BASE_URL.$path, $query);
    }

    /**
     * Proves the whole auth chain works: mints a token and makes a real call
     * with it (NABIL — always listed). Throws with a specific reason if not.
     */
    public function verify(): void
    {
        $response = $this->get('/api/nots/security/131', timeout: 15);

        if ($response->failed() || $response->json('securityData.symbol') === null) {
            throw new RuntimeException(
                "Token minted but the API rejected it (HTTP {$response->status()}) — NEPSE's token algorithm ".
                'may have changed. NepalStockTokenService needs re-deriving from a fresh css.wasm (see its docblock).'
            );
        }
    }
}
