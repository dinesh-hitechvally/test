<?php

namespace App\GraphQL\Resolvers;

use App\GraphQL\Resolver;
use App\Models\DataQualityFlag;
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

    public function dataQualityFlags($root, array $args): array
    {
        // 'sector' isn't a real column (Stock::toArray() derives it from the sector_id relation) —
        // select the FK instead and let that lazy-load when the collection serializes.
        $query = DataQualityFlag::with('stock:id,symbol,company_name,sector_id')->latest('detected_at');

        if (! empty($args['severity'])) {
            $query->where('severity', $args['severity']);
        }

        $query->when($args['resolved'] ?? false, fn ($q) => $q->whereNotNull('resolved_at'), fn ($q) => $q->whereNull('resolved_at'));

        return $this->plain($query->get());
    }

    public function resolveDataQualityFlag($root, array $args): array
    {
        $flag = DataQualityFlag::with('stock:id,symbol,company_name,sector_id')->findOrFail($args['id']);
        $flag->update(['resolved_at' => now()]);

        return $this->plain($flag);
    }
}
