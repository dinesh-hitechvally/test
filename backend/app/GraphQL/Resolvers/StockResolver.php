<?php

namespace App\GraphQL\Resolvers;

use App\GraphQL\ApiError;
use App\GraphQL\Resolver;
use App\Http\Requests\Stock\StockSignalsRequest;
use App\Http\Requests\Stock\StoreStockRequest;
use App\Services\Ai\AiStockOpinionService;
use App\Services\Analysis\Forecasting\NextCloseEstimatorService;
use App\Services\DataSources\CorporateActionsRefreshService;
use App\Services\MachineLearning\MlDirectionPredictorService;
use App\Services\Stocks\StockService;
use GraphQL\Type\Definition\ResolveInfo;

/** The stock list and everything on a Stock Detail page. */
class StockResolver extends Resolver
{
    public function __construct(private readonly StockService $stocks) {}

    /**
     * Loads only what the query asks for: the web app's column picker (Stocks page > Screen Options)
     * sends just the fields of the columns you left on, so the AI opinions, prices, signals and
     * change_pct for every stock are not fetched when nobody will see them.
     */
    public function stocks($root, array $args, $context, ResolveInfo $info): array
    {
        $selected = $info->getFieldSelection();
        $with = array_keys(array_filter([
            'latestPrice' => isset($selected['latest_price']),
            'latestSignal' => isset($selected['latest_signal']),
            'aiOpinion' => isset($selected['ai_opinion']),
        ]));

        return $this->plain($this->stocks->list($args['search'] ?? null, $with, isset($selected['change_pct'])));
    }

    public function stock($root, array $args): array
    {
        return $this->plain($this->stocks->findBySymbol($args['symbol'], ['sector', 'latestPrice', 'latestSignal', 'fundamental']));
    }

    public function createStock($root, array $args): array
    {
        return $this->plain($this->stocks->create($this->validated(StoreStockRequest::class, $args)));
    }

    public function prices($root, array $args): array
    {
        return $this->plain($this->stocks->recentPrices($this->stocks->findBySymbol($args['symbol']), $args['days']));
    }

    public function indicators($root, array $args): array
    {
        return $this->plain($this->stocks->recentIndicators($this->stocks->findBySymbol($args['symbol']), $args['days']));
    }

    public function forecasts($root, array $args): array
    {
        return $this->plain($this->stocks->recentForecasts($this->stocks->findBySymbol($args['symbol']), $args['days']));
    }

    public function signalHistory($root, array $args): array
    {
        $filters = $this->validated(StockSignalsRequest::class, array_intersect_key($args, array_flip(['from', 'to', 'signal'])));

        return $this->plain($this->stocks->signalHistory($this->stocks->findBySymbol($args['symbol']), $filters, $args['page'], $args['per_page']));
    }

    public function mlPrediction($root, array $args): array
    {
        return $this->plain(app(MlDirectionPredictorService::class)->predictionReport($this->stocks->findBySymbol($args['symbol'])));
    }

    public function aiOpinion($root, array $args): array
    {
        return $this->plain(app(AiStockOpinionService::class)->getStoredOpinion($this->stocks->findBySymbol($args['symbol'], ['aiOpinion'])));
    }

    public function nextCloseForecast($root, array $args): array
    {
        return $this->plain(app(NextCloseEstimatorService::class)->getStoredForecast($this->stocks->findBySymbol($args['symbol'], ['latestForecast'])));
    }

    public function dividends($root, array $args): array
    {
        return $this->plain($this->stocks->dividends($this->stocks->findBySymbol($args['symbol'])));
    }

    public function rightShares($root, array $args): array
    {
        return $this->plain($this->stocks->rightShares($this->stocks->findBySymbol($args['symbol'])));
    }

    public function refreshCorporateActions($root, array $args): array
    {
        $result = app(CorporateActionsRefreshService::class)->refresh($this->stocks->findBySymbol($args['symbol']));

        if ($result['sources'] === []) {
            throw new ApiError('No dividend data available right now from nepalstock.com.', 502);
        }

        return $this->plain($result);
    }
}
