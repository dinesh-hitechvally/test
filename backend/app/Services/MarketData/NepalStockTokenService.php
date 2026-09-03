<?php

namespace App\Services\MarketData;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Handles nepalstock.com's (the official NEPSE site) token authentication.
 * Their real market-data API requires an accessToken that isn't issued
 * plainly — the server returns a raw token plus 5 rotating "salts", and the
 * site's own WebAssembly module (fetched as "css.wasm" — a deliberately
 * misleading name) exports functions that turn those salts into character
 * -index positions used to splice the raw token into the real one.
 *
 * There's no way to replicate this with plain arithmetic — the salt-to-index
 * mapping is defined by the WASM binary itself. So this is the one place in
 * the app that shells out to another runtime (Node, already installed for
 * the frontend) to actually execute that WASM. Everything else (fetching,
 * splicing the token string, the real API calls) stays in PHP.
 */
class NepalStockTokenService
{
    private const BASE_URL = 'https://www.nepalstock.com';

    private const TOKEN_PATH = '/api/authenticate/prove';

    private const WASM_URL = self::BASE_URL.'/assets/prod/css.wasm';

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome Safari';

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

        $indices = $this->computeSpliceIndices(
            $data['salt1'], $data['salt2'], $data['salt3'], $data['salt4'], $data['salt5']
        );

        return $this->splice($data['accessToken'], $indices['access']);
    }

    /**
     * @return array{access: array{n: int, l: int, o: int, p: int, q: int}, refresh: array{a: int, b: int, c: int, d: int, e: int}}
     */
    private function computeSpliceIndices(int $s1, int $s2, int $s3, int $s4, int $s5): array
    {
        $script = base_path('resources/nepse/token-indices.cjs');

        $result = Process::timeout(15)->run(['node', $script, $this->wasmPath(), $s1, $s2, $s3, $s4, $s5]);

        if ($result->failed()) {
            if (str_contains($result->errorOutput(), 'not found') || $result->exitCode() === 127) {
                throw new RuntimeException('NEPSE token computation requires Node.js on PATH — none was found.');
            }

            throw new RuntimeException('NEPSE token computation failed: '.$result->errorOutput());
        }

        $decoded = json_decode($result->output(), true);

        if (! is_array($decoded) || ! isset($decoded['access'])) {
            throw new RuntimeException('NEPSE token computation returned an unexpected result: '.$result->output());
        }

        return $decoded;
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

    /**
     * The WASM binary is a static asset that rarely changes — fetched once
     * and cached locally rather than re-downloaded on every token request.
     */
    private function wasmPath(): string
    {
        $path = storage_path('app/nepse/css.wasm');

        if (! file_exists($path)) {
            File::ensureDirectoryExists(dirname($path));

            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])->timeout(15)->get(self::WASM_URL);
            $response->throw();

            file_put_contents($path, $response->body());
        }

        return $path;
    }
}
