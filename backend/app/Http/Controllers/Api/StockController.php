<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stock\ImportPricesCsvRequest;
use App\Http\Requests\Stock\StockSignalsRequest;
use App\Http\Requests\Stock\StoreStockRequest;
use App\Services\Ai\AiStockOpinionService;
use App\Services\Analysis\Forecasting\NextCloseEstimatorService;
use App\Services\DataSources\CorporateActionsRefreshService;
use App\Services\DataSources\Csv\CsvPriceImportService;
use App\Services\MachineLearning\MlDirectionPredictorService;
use App\Services\Stocks\StockService;
use Illuminate\Http\Request;

class StockController extends Controller
{
    public function __construct(private readonly StockService $stocks) {}

    public function index(Request $request)
    {
        return response()->json($this->stocks->list($request->query('search')));
    }

    public function store(StoreStockRequest $request)
    {
        return response()->json($this->stocks->create($request->validated()), 201);
    }

    public function show(string $symbol)
    {
        return response()->json($this->stocks->findBySymbol($symbol, ['sector', 'latestPrice', 'latestSignal', 'fundamental']));
    }

    public function prices(string $symbol, Request $request)
    {
        return response()->json($this->stocks->recentPrices($this->stocks->findBySymbol($symbol), $request->integer('days', 365)));
    }

    public function indicators(string $symbol, Request $request)
    {
        return response()->json($this->stocks->recentIndicators($this->stocks->findBySymbol($symbol), $request->integer('days', 365)));
    }

    public function forecasts(string $symbol, Request $request)
    {
        return response()->json($this->stocks->recentForecasts($this->stocks->findBySymbol($symbol), $request->integer('days', 365)));
    }

    public function signals(string $symbol, StockSignalsRequest $request)
    {
        return response()->json($this->stocks->signalHistory(
            $this->stocks->findBySymbol($symbol),
            $request->validated(),
            $request->integer('page', 1),
            $request->integer('per_page', 30),
        ));
    }

    public function mlPrediction(string $symbol, MlDirectionPredictorService $predictor)
    {
        return response()->json($predictor->predictionReport($this->stocks->findBySymbol($symbol)));
    }

    public function aiOpinion(string $symbol, AiStockOpinionService $ai)
    {
        return response()->json($ai->getStoredOpinion($this->stocks->findBySymbol($symbol, ['aiOpinion'])));
    }

    public function nextCloseForecast(string $symbol, NextCloseEstimatorService $estimator)
    {
        return response()->json($estimator->getStoredForecast($this->stocks->findBySymbol($symbol, ['latestForecast'])));
    }

    /** Recalculation of the imported stocks follows automatically (StockPricesUpdated). */
    public function importCsv(ImportPricesCsvRequest $request, CsvPriceImportService $importer)
    {
        return response()->json($importer->import($request->validated('file'), $request->validated('symbol')));
    }

    public function dividends(string $symbol)
    {
        return response()->json($this->stocks->dividends($this->stocks->findBySymbol($symbol)));
    }

    public function rightShares(string $symbol)
    {
        return response()->json($this->stocks->rightShares($this->stocks->findBySymbol($symbol)));
    }

    public function fetchCorporateActions(string $symbol, CorporateActionsRefreshService $refresher)
    {
        $result = $refresher->refresh($this->stocks->findBySymbol($symbol));

        return $result['sources'] === []
            ? response()->json(['message' => 'No dividend data available right now from nepalstock.com.'], 502)
            : response()->json($result);
    }
}
