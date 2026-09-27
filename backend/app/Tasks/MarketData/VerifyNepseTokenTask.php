<?php

namespace App\Tasks\MarketData;

use App\Services\DataSources\NepalStock\NepalStockClient;
use App\Tasks\Task;

/** On-demand diagnostic — the first thing to run when every nepalstock.com fetch starts failing at once. */
class VerifyNepseTokenTask extends Task
{
    public function __construct(private readonly NepalStockClient $client) {}

    public function name(): string
    {
        return 'nepse:verify-token';
    }

    public function description(): string
    {
        return 'Mint a nepalstock.com access token and confirm it actually authenticates against the real API';
    }

    public function logFile(): string
    {
        return 'nepse-verify-token.log';
    }

    public function handle(): string
    {
        $this->client->verify();

        return 'Token verified — nepalstock.com accepted it and returned real data for NABIL.';
    }
}
