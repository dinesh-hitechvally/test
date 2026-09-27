<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Report\RuleScanRequest;
use App\Services\Analysis\Forecasting\NextCloseEstimatorService;
use App\Services\Analysis\Signals\SignalRuleScanner;
use App\Services\Reports\DividendReportService;
use App\Services\Reports\InvestmentHorizonService;
use App\Services\Reports\MarketReportService;
use App\Services\Reports\SectorReportService;
use App\Services\Reports\StockReportService;
use App\Services\Stocks\StockService;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(
        private readonly MarketReportService $reports,
        private readonly StockService $stocks,
    ) {}

    public function dashboard()
    {
        return response()->json($this->reports->dashboardSummary());
    }

    public function market()
    {
        return response()->json($this->reports->marketOverview());
    }

    public function sectors(SectorReportService $sectors)
    {
        return response()->json($sectors->sectors());
    }

    public function sector(Request $request, SectorReportService $sectors)
    {
        $name = $request->query('name');

        if (! $name) {
            return response()->json(['message' => 'A sector name is required.'], 422);
        }

        $report = $sectors->sector($name);

        return $report
            ? response()->json($report)
            : response()->json(['message' => "No stocks found for sector [{$name}]."], 404);
    }

    public function stock(string $symbol, StockReportService $report)
    {
        return response()->json($report->summary($this->stocks->findBySymbol($symbol, ['sector', 'latestPrice', 'latestSignal'])));
    }

    public function dividends(Request $request, DividendReportService $dividends)
    {
        return response()->json($dividends->dividendReport($request->query('sector')));
    }

    public function nextCloseAccuracy(NextCloseEstimatorService $estimator)
    {
        return response()->json($estimator->accuracySummary());
    }

    public function longTerm(Request $request, InvestmentHorizonService $horizons)
    {
        return response()->json(['candidates' => $horizons->rankLongTermCandidates($request->query('sector'))]);
    }

    public function midTerm(Request $request, InvestmentHorizonService $horizons)
    {
        return response()->json(['candidates' => $horizons->rankMidTermCandidates($request->query('sector'))]);
    }

    public function shortTerm(Request $request, InvestmentHorizonService $horizons)
    {
        return response()->json(['candidates' => $horizons->rankShortTermCandidates($request->query('sector'))]);
    }

    public function technical(string $symbol, StockReportService $report)
    {
        return response()->json($report->technical($this->stocks->findBySymbol($symbol, ['sector'])));
    }

    public function analyst(string $symbol, StockReportService $report)
    {
        return response()->json($report->analyst($this->stocks->findBySymbol($symbol, ['sector', 'latestPrice', 'latestSignal'])));
    }

    public function rules(SignalRuleScanner $scanner)
    {
        return response()->json($scanner->rules());
    }

    public function ruleScan(RuleScanRequest $request, SignalRuleScanner $scanner)
    {
        $rules = $request->validated('rules');

        if ($unknown = $scanner->unknownKeys(array_unique($rules))) {
            return response()->json(['message' => 'Unknown rule key(s): '.implode(', ', $unknown)], 422);
        }

        return response()->json($scanner->scan($rules, $request->validated('mode') ?? 'any'));
    }
}
