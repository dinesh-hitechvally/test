<?php

namespace App\GraphQL\Resolvers;

use App\GraphQL\Resolver;
use App\Models\ScrapeLog;
use App\Services\Analysis\Patterns\CandlestickPatternScanner;
use App\Services\Analysis\Signals\SignalAccuracyService;
use App\Services\Analysis\Signals\SignalFeedService;
use App\Services\DataSources\ScrapeHealthService;
use App\Services\Portfolio\PriceAlertService;
use App\Services\Reports\IndexReportService;
use App\Services\Reports\ScreenerService;

/** Market-wide lists: signal feeds, screener, indices, patterns, alerts, data-source health. */
class MarketResolver extends Resolver
{
    public function todaySignals($root, array $args): array
    {
        return $this->plain(app(SignalFeedService::class)->today($args['signal'] ?? null));
    }

    public function actionableSignals($root, array $args): array
    {
        return $this->plain(app(SignalFeedService::class)->actionable(($args['bias'] ?? 'buy') === 'sell' ? 'sell' : 'buy'));
    }

    public function signalAccuracy(): array
    {
        return $this->plain(app(SignalAccuracyService::class)->latestReport());
    }

    public function priceAlerts(): array
    {
        return $this->plain(app(PriceAlertService::class)->forUser($this->user()));
    }

    public function indices($root, array $args): array
    {
        return $this->plain(app(IndexReportService::class)->history($args['days']));
    }

    public function candlestickPatterns(): array
    {
        return $this->plain(app(CandlestickPatternScanner::class)->scan());
    }

    public function screener(): array
    {
        return $this->plain(app(ScreenerService::class)->screener());
    }

    public function fiftyTwoWeek(): array
    {
        return $this->plain(app(ScreenerService::class)->fiftyTwoWeek());
    }

    public function scrapeLogs($root, array $args): array
    {
        return $this->plain(ScrapeLog::latest('created_at')->limit($args['limit'])->get());
    }

    /** The Data Source Settings page: is CRON_SECRET set, and which stocks have a failed fetch. */
    public function dataSourceStatus(): array
    {
        return $this->plain([
            'secret_configured' => filled(config('services.cron.secret')),
            'flagged_stocks' => app(ScrapeHealthService::class)->flaggedStocks(),
        ]);
    }
}
