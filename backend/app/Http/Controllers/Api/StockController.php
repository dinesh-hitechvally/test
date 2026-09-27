<?php

namespace App\Http\Controllers\Api;

use App\Contracts\PriceHistorySource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Stock\ImportPricesCsvRequest;
use App\Http\Requests\Stock\StockSignalsRequest;
use App\Http\Requests\Stock\StoreStockRequest;
use App\Models\Sector;
use App\Models\Stock;
use App\Services\Ai\AiStockOpinionService;
use App\Services\DataSources\Csv\CsvPriceImportService;
use App\Services\Reports\MarketReportService;
use App\Services\MachineLearning\MlDirectionPredictorService;
use App\Services\DataSources\CorporateActionsRefreshService;
use App\Services\DataSources\NepalStock\NepalStockHistoryService;
use App\Services\Analysis\Forecasting\NextCloseEstimatorService;
use Illuminate\Http\Request;
use Throwable;

class StockController extends Controller
{
    public function index(Request $request, MarketReportService $reports)
    {
        $query = Stock::query()->with(['sector', 'latestPrice', 'latestSignal', 'aiOpinion'])->orderBy('symbol');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('symbol', 'like', "%{$search}%")
                    ->orWhere('company_name', 'like', "%{$search}%");
            });
        }

        $stocks = $query->get();
        $changes = $reports->priceChanges();

        $stocks->each(function ($stock) use ($changes) {
            $stock->change_pct = $changes->get($stock->id)['change_pct'] ?? null;
        });

        return response()->json($stocks);
    }

    public function store(StoreStockRequest $request)
    {
        $validated = $request->validated();

        $validated['symbol'] = strtoupper($validated['symbol']);

        $sectorName = $validated['sector'] ?? null;
        unset($validated['sector']);
        if ($sectorName !== null) {
            $validated['sector_id'] = Sector::firstOrCreate(['name' => $sectorName])->id;
        }

        $stock = Stock::create($validated);

        return response()->json($stock->load('sector'), 201);
    }

    public function show(string $symbol)
    {
        $stock = $this->findStock($symbol, ['sector', 'latestPrice', 'latestSignal', 'fundamental']);

        return response()->json($stock);
    }

    public function prices(string $symbol, Request $request)
    {
        $stock = $this->findStock($symbol);
        $days = (int) $request->query('days', 365);

        $prices = $stock->dailyPrices()->orderByDesc('trade_date')->limit($days)->get()->reverse()->values();

        return response()->json($prices);
    }

    public function indicators(string $symbol, Request $request)
    {
        $stock = $this->findStock($symbol);
        $days = (int) $request->query('days', 365);

        $indicators = $stock->technicalIndicators()->orderByDesc('trade_date')->limit($days)->get()->reverse()->values();

        return response()->json($indicators);
    }

    /**
     * Historical forecasts for the Price & Moving Averages chart, keyed by
     * their own trade_date (the day each was generated from), not the day
     * being predicted — daily_prices only ever contains actual trading
     * days, so the frontend plots each one against the *next* entry in the
     * price series rather than needing a separately stored target date.
     */
    public function forecasts(string $symbol, Request $request)
    {
        $stock = $this->findStock($symbol);
        $days = (int) $request->query('days', 365);

        $forecasts = $stock->forecasts()
            ->orderByDesc('trade_date')
            ->limit($days)
            ->get(['trade_date', 'next_close'])
            ->reverse()
            ->values();

        return response()->json($forecasts);
    }

    public function signals(string $symbol, StockSignalsRequest $request)
    {
        $validated = $request->validated();

        $stock = $this->findStock($symbol);
        $perPage = min((int) $request->query('per_page', 30), 200);
        $page = max((int) $request->query('page', 1), 1);

        $query = $stock->signals();
        if ($validated['from'] ?? null) {
            $query->where('trade_date', '>=', $validated['from']);
        }
        if ($validated['to'] ?? null) {
            $query->where('trade_date', '<=', $validated['to']);
        }
        if ($validated['signal'] ?? null) {
            $query->whereIn('signal', $validated['signal']);
        }

        $total = (clone $query)->count();

        // Page 1 = the most recent `per_page` days (within the date range, if
        // one's set), page 2 = the `per_page` days before that, etc. — paged
        // back from the newest matching row, not forward from the oldest,
        // since that's what users actually want when browsing a signal
        // history table (newest first).
        $signals = $query
            ->orderByDesc('trade_date')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get()
            ->reverse()
            ->values();

        // Each signal's own day's forecast — what NextCloseEstimatorService
        // projected for the following close as of that trade_date — merged
        // in so the history table can show prediction vs. what actually
        // happened next to it, without a second round trip.
        $forecasts = $stock->forecasts()
            ->whereIn('trade_date', $signals->pluck('trade_date')->map(fn ($d) => $d->toDateString()))
            ->get()
            ->keyBy(fn ($f) => $f->trade_date->toDateString());

        $signals->each(function ($signal) use ($forecasts) {
            $forecast = $forecasts->get($signal->trade_date->toDateString());
            $signal->forecast_price = $forecast ? (float) $forecast->next_close : null;
        });

        return response()->json([
            'data' => $signals,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => (int) ceil($total / $perPage),
        ]);
    }


    public function mlPrediction(string $symbol, MlDirectionPredictorService $predictor)
    {
        $stock = $this->findStock($symbol);
        $model = $predictor->latestMetrics();

        if ($model === null) {
            return response()->json(['prediction' => null, 'model' => null]);
        }

        try {
            $prediction = $predictor->predict($stock);
        } catch (Throwable $e) {
            // The saved model file and MlFeatureBuilder's feature set can briefly
            // disagree right after a feature-set change and before the next
            // retrain finishes — degrade to "no prediction" rather than a 500.
            $prediction = null;
        }

        return response()->json([
            'prediction' => $prediction,
            'model' => [
                'trained_at' => $model->trained_at,
                'horizon_days' => $model->horizon_days,
                'accuracy' => (float) $model->accuracy,
                'baseline_accuracy' => (float) $model->baseline_accuracy,
                'beats_baseline' => $model->beatsBaseline(),
                'precision' => (float) $model->precision,
                'recall' => (float) $model->recall,
                'test_samples' => $model->test_samples,
                'stocks_used' => $model->stocks_used,
            ],
        ]);
    }

    public function aiOpinion(string $symbol, AiStockOpinionService $ai)
    {
        $stock = $this->findStock($symbol, ['aiOpinion']);

        return response()->json($ai->getStoredOpinion($stock));
    }

    public function nextCloseForecast(string $symbol, NextCloseEstimatorService $estimator)
    {
        $stock = $this->findStock($symbol, ['latestForecast']);

        return response()->json($estimator->getStoredForecast($stock));
    }

    /** Recalculation of the imported stocks follows automatically (StockPricesUpdated). */
    public function importCsv(ImportPricesCsvRequest $request, CsvPriceImportService $importer)
    {
        $validated = $request->validated();

        return response()->json($importer->import($validated['file'], $validated['symbol'] ?? null));
    }

    public function fetchFullHistory(string $symbol, PriceHistorySource $history)
    {
        $stock = $this->findStock($symbol);

        try {
            $result = $history->fetchHistory($stock);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Full history fetch failed: '.$e->getMessage()], 502);
        }

        return response()->json($result);
    }

    public function fetchNepseHistory(string $symbol, NepalStockHistoryService $history)
    {
        $stock = $this->findStock($symbol);

        try {
            $result = $history->fetchHistory($stock);
        } catch (Throwable $e) {
            return response()->json(['message' => 'NEPSE official history fetch failed: '.$e->getMessage()], 502);
        }

        return response()->json($result);
    }

    public function dividends(string $symbol)
    {
        $stock = $this->findStock($symbol);

        return response()->json(
            $stock->dividends()->orderByDesc('fiscal_year')->get()
        );
    }

    public function rightShares(string $symbol)
    {
        $stock = $this->findStock($symbol);

        return response()->json(
            $stock->rightShares()->orderByDesc('opening_date')->get()
        );
    }

    public function fetchCorporateActions(string $symbol, CorporateActionsRefreshService $refresher)
    {
        $stock = $this->findStock($symbol);
        $result = $refresher->refresh($stock);

        if ($result['sources'] === []) {
            return response()->json([
                'message' => 'No dividend data available right now from nepalstock.com.',
            ], 502);
        }

        return response()->json($result);
    }

    private function findStock(string $symbol, array $with = []): Stock
    {
        return Stock::with($with)->where('symbol', strtoupper($symbol))->firstOrFail();
    }
}
