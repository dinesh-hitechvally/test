<?php

namespace App\Console\Commands;

use App\Services\MarketData\NepalStockTokenService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * NepalStockTokenService computes the token's splice positions with a pure-PHP
 * formula reverse-engineered from NEPSE's WASM module, instead of executing
 * that module. If NEPSE ever changes the algorithm, this stops working
 * silently in normal use (a scrape just starts failing) — this command is a
 * one-line way to check the token itself is still valid before assuming
 * something else is wrong, by minting one and making one real API call with it.
 */
#[Signature('nepse:verify-token')]
#[Description('Mint a nepalstock.com access token and confirm it actually authenticates against the real API')]
class VerifyNepseToken extends Command
{
    public function handle(NepalStockTokenService $tokens): int
    {
        try {
            $token = $tokens->getAccessToken();
        } catch (Throwable $e) {
            $this->error('Token minting failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $response = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome Safari',
            'Referer' => 'https://www.nepalstock.com/',
            'Authorization' => 'Salter '.$token,
        ])->timeout(15)->get('https://www.nepalstock.com/api/nots/security/131'); // NABIL — always listed

        if ($response->failed() || $response->json('securityData.symbol') === null) {
            $this->error(
                "Token minted but the API rejected it (HTTP {$response->status()}) — NEPSE's token algorithm ".
                'may have changed. NepalStockTokenService needs re-deriving from a fresh css.wasm (see its docblock).'
            );

            return self::FAILURE;
        }

        $this->info('Token verified — nepalstock.com accepted it and returned real data for NABIL.');

        return self::SUCCESS;
    }
}
