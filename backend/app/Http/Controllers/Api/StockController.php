<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Services\MarketData\CsvPriceImportService;
use App\Services\MarketData\MarketReportService;
use App\Services\MarketData\MlDirectionPredictorService;
use App\Services\MarketData\CorporateActionsRefreshService;
use App\Services\MarketData\NepalStockHistoryService;
use App\Services\MarketData\RecalculationPipeline;
use App\Services\MarketData\SharesansarHistoryService;
use Illuminate\Http\Request;
use Throwable;

class StockController extends Controller
{
    public function index(Request $request, MarketReportService $reports)
    {
        $query = Stock::query()->with(['latestPrice', 'latestSignal'])->orderBy('symbol');

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

    public function store(Request $request)
    {
        $validated = $request->validate([
            'symbol' => ['required', 'string', 'max:20', 'unique:stocks,symbol'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'sector' => ['nullable', 'string', 'max:100'],
        ]);

        $validated['symbol'] = strtoupper($validated['symbol']);

        $stock = Stock::create($validated);

        return response()->json($stock, 201);
    }

    public function show(string $symbol)
    {
        $stock = $this->findStock($symbol, ['latestPrice', 'latestSignal']);

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

    public function signals(string $symbol, Request $request)
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

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

    public function importCsv(Request $request, CsvPriceImportService $importer, RecalculationPipeline $pipeline)
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt'],
            'symbol' => ['nullable', 'string', 'max:20'],
        ]);

        $result = $importer->import($validated['file'], $validated['symbol'] ?? null);

        $stocks = Stock::whereIn('id', $result['affected_stock_ids'])->get();
        $pipeline->runForMany($stocks);

        return response()->json($result);
    }

    public function fetchFullHistory(string $symbol, SharesansarHistoryService $history)
    {
        $stock = $this->findStock($symbol);

        try {
            $result = $history->fetchFullHistory($stock);
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
