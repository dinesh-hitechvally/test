<?php

namespace App\Services\MarketData;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Handles nepalstock.com's (the official NEPSE site) token authentication —
 * pure PHP, no Node/browser/WASM runtime of any kind at request time.
 *
 * The site's `/api/authenticate/prove` endpoint returns a raw accessToken
 * plus 5 salts, and a real token is that accessToken with 6 characters
 * spliced out at 5 positions derived from the salts. NEPSE's own frontend
 * computes those 5 positions by running the salts through a small WASM
 * module (fetched as "css.wasm") — but that module turned out to be tiny
 * (750 bytes) and, once disassembled (wasm2wat), to boil down to genuinely
 * simple arithmetic: every one of its 5 exported functions only ever reads
 * its *second* argument (i.e. only $salt2 matters — salt1/3/4/5 are unused
 * red herrings), splits it into its hundreds/tens/ones digits, sums them,
 * looks that sum up in a 28-entry constant table, and adds a small
 * per-function base constant (each function also happens to add back one
 * or two of those same digits a second time — an artifact of how the
 * WASM was compiled, not a deliberate design, but it has to be replicated
 * exactly). Verified against the live site across multiple independent
 * salt exchanges: this produces byte-identical splice positions to the
 * real WASM, and the resulting token is accepted by the real API.
 *
 * If NEPSE ever changes this algorithm, `php artisan nepse:verify-token`
 * (or any real API call through this service) will start failing loudly —
 * at that point the WASM module needs re-disassembling, not this file
 * patched blindly.
 */
class NepalStockTokenService
{
    private const BASE_URL = 'https://www.nepalstock.com';

    private const TOKEN_PATH = '/api/authenticate/prove';

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome Safari';

    /**
     * TABLE[digit sum of the relevant salt, 0-27] — read directly out of
     * css.wasm's data section (bytes at memory offset 1024), not guessed.
     */
    private const TABLE = [
        5, 8, 4, 7, 9, 4, 6, 9, 5, 5, 6, 5, 3, 5, 4, 4, 9, 6, 6, 8, 8, 6, 8, 6, 5, 8, 4, 9,
    ];

    public function getAccessToken(): string
    {
        $response = Http::withHeaders([
            'User-Agent' => self::USER_AGENT,
            'Referer' => self::BASE_URL.'/',
            'Accept' => 'application/json',
        ])->timeout(15)->get(self::BASE_URL.self::TOKEN_PATH);

        $response->throw();
        $data = $response->json();

        foreach (['salt1', 'salt2', 'salt3', 'salt4', 'salt5', 'accessToken'] as $key) {
            if (! isset($data[$key])) {
                throw new RuntimeException("NEPSE token response is missing [{$key}] — the site's auth flow may have changed.");
            }
        }

        // Only ever salt2 — see class docblock. The other 4 salts are part
        // of the real request/response but never actually read by the WASM.
        $indices = $this->spliceIndices((int) $data['salt2']);

        return $this->splice($data['accessToken'], $indices);
    }

    /**
     * @return array{n: int, l: int, o: int, p: int, q: int}
     */
    private function spliceIndices(int $salt): array
    {
        $hundreds = intdiv($salt, 100) % 10;
        $tens = intdiv($salt, 10) % 10;
        $ones = $salt % 10;
        $table = self::TABLE[$hundreds + $tens + $ones];

        return [
            'n' => $table + 22,
            'l' => ($hundreds + $tens) + $table + 32,
            'o' => ($hundreds + $tens) + $table + 60,
            'p' => $tens + $table + 88,
            'q' => $hundreds + $table + 110,
        ];
    }

    /**
     * @param  array{n: int, l: int, o: int, p: int, q: int}  $idx
     */
    private function splice(string $token, array $idx): string
    {
        return substr($token, 0, $idx['n'])
            .substr($token, $idx['n'] + 1, $idx['l'] - $idx['n'] - 1)
            .substr($token, $idx['l'] + 1, $idx['o'] - $idx['l'] - 1)
            .substr($token, $idx['o'] + 1, $idx['p'] - $idx['o'] - 1)
            .substr($token, $idx['p'] + 1, $idx['q'] - $idx['p'] - 1)
            .substr($token, $idx['q'] + 1);
    }
}
