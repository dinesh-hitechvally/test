<?php

namespace App\GraphQL\Resolvers;

use App\GraphQL\ApiError;
use App\GraphQL\Resolver;
use App\Http\Requests\Report\RuleScanRequest;
use App\Services\Analysis\Forecasting\NextCloseEstimatorService;
use App\Services\Analysis\Signals\SignalRuleScanner;
use App\Services\Reports\DividendReportService;
use App\Services\Reports\InvestmentHorizonService;
use App\Services\Reports\MarketReportService;
use App\Services\Reports\PriceStatisticsService;
use App\Services\Reports\SectorReportService;
use App\Services\Reports\StockReportService;
use App\Services\Stocks\StockService;

/** The Reports pages. */
class ReportResolver extends Resolver
{
    public function __construct(private readonly StockService $stocks) {}

    public function dashboard(): array
    {
        return $this->plain(app(MarketReportService::class)->dashboardSummary());
    }

    public function market(): array
    {
        return $this->plain(app(MarketReportService::class)->marketOverview());
    }

    public function sectorDetail($root, array $args): array
    {
        return $this->plain(app(SectorReportService::class)->forId((int) $args['id']))
            ?? throw new ApiError("No sector with id {$args['id']}.", 404);
    }

    public function sectorPerformance(): array
    {
        return $this->plain(app(PriceStatisticsService::class)->sectorPerformance());
    }

    public function sectors(): array
    {
        return $this->plain(app(SectorReportService::class)->sectors());
    }

    public function sector($root, array $args): array
    {
        if (blank($args['name'] ?? null)) {
            throw new ApiError('A sector name is required.', 422);
        }

        return $this->plain(app(SectorReportService::class)->sector($args['name']))
            ?? throw new ApiError("No stocks found for sector [{$args['name']}].", 404);
    }

    public function stock($root, array $args): array
    {
        return $this->plain(app(StockReportService::class)->summary($this->stocks->findBySymbol($args['symbol'], ['sector', 'latestPrice', 'latestSignal'])));
    }

    public function technical($root, array $args): array
    {
        return $this->plain(app(StockReportService::class)->technical($this->stocks->findBySymbol($args['symbol'], ['sector'])));
    }

    public function analyst($root, array $args): array
    {
        return $this->plain(app(StockReportService::class)->analyst($this->stocks->findBySymbol($args['symbol'], ['sector', 'latestPrice', 'latestSignal'])));
    }

    public function dividends($root, array $args): array
    {
        return $this->plain(app(DividendReportService::class)->dividendReport($args['sector'] ?? null));
    }

    public function nextCloseAccuracy(): array
    {
        return $this->plain(app(NextCloseEstimatorService::class)->accuracySummary());
    }

    public function longTerm($root, array $args): array
    {
        return $this->plain(app(InvestmentHorizonService::class)->rankLongTermCandidates($args['sector'] ?? null));
    }

    public function midTerm($root, array $args): array
    {
        return $this->plain(app(InvestmentHorizonService::class)->rankMidTermCandidates($args['sector'] ?? null));
    }

    public function shortTerm($root, array $args): array
    {
        return $this->plain(app(InvestmentHorizonService::class)->rankShortTermCandidates($args['sector'] ?? null));
    }

    public function signalRules(): array
    {
        return $this->plain(app(SignalRuleScanner::class)->rules());
    }

    public function ruleScan($root, array $args): array
    {
        $validated = $this->validated(RuleScanRequest::class, $args);
        $scanner = app(SignalRuleScanner::class);

        if ($unknown = $scanner->unknownKeys(array_unique($validated['rules']))) {
            throw new ApiError('Unknown rule key(s): '.implode(', ', $unknown), 422);
        }

        return $this->plain($scanner->scan($validated['rules'], $validated['mode'] ?? 'any'));
    }
}
