<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Portfolio\SetPositionTargetRequest;
use App\Http\Requests\Portfolio\StorePortfolioRequest;
use App\Http\Requests\Portfolio\StoreTransactionRequest;
use App\Models\Portfolio;
use App\Models\PositionTarget;
use App\Models\Stock;
use App\Services\Portfolio\Export\CsvPortfolioExporter;
use App\Services\Portfolio\Export\ExcelPortfolioExporter;
use App\Services\Portfolio\Export\PdfPortfolioExporter;
use App\Services\Portfolio\Export\PortfolioExporter;
use App\Services\Portfolio\PortfolioValuationService;
use Illuminate\Http\Request;
use Throwable;

class PortfolioController extends Controller
{
    public function index(Request $request, PortfolioValuationService $valuation)
    {
        $portfolios = $request->user()->portfolios()->get();

        $portfolios->each(function ($portfolio) use ($valuation) {
            $portfolio->summary = $valuation->summary($portfolio);
        });

        return response()->json($portfolios);
    }

    public function store(StorePortfolioRequest $request)
    {
        $portfolio = $request->user()->portfolios()->create($request->validated());

        return response()->json($portfolio, 201);
    }

    public function show(Request $request, $portfolioId, PortfolioValuationService $valuation)
    {
        $portfolio = $this->findPortfolio($request, $portfolioId);

        return response()->json([
            'portfolio' => $portfolio,
            'summary' => $valuation->summary($portfolio),
            'holdings' => $valuation->holdings($portfolio),
            'realized' => $valuation->realizedPnl($portfolio),
        ]);
    }

    public function transactions(Request $request, $portfolioId)
    {
        $portfolio = $this->findPortfolio($request, $portfolioId);

        return response()->json($portfolio->transactionHistory()->get());
    }

    public function storeTransaction(StoreTransactionRequest $request, $portfolioId, PortfolioValuationService $valuation)
    {
        $portfolio = $this->findPortfolio($request, $portfolioId);
        $validated = $request->validated();

        try {
            $valuation->assertTransactionIsValid(
                $portfolio,
                (int) $validated['stock_id'],
                $validated['type'],
                (int) $validated['quantity'],
                $validated['transaction_date'],
            );
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $transaction = $portfolio->transactions()->create([
            ...$validated,
            'fees' => $validated['fees'] ?? 0,
        ]);

        return response()->json($transaction->load('stock:id,symbol,company_name'), 201);
    }

    public function destroyTransaction(Request $request, $portfolioId, $transactionId)
    {
        $portfolio = $this->findPortfolio($request, $portfolioId);
        $portfolio->transactions()->findOrFail((int) $transactionId)->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    /**
     * Sets or clears the stop-loss / take-profit levels an investor wants
     * watched for one holding. Independent of the transaction ledger —
     * upsert semantics, so this can be set, edited, or cleared (nulls) at
     * any time without touching buy/sell history.
     */
    public function setTarget(SetPositionTargetRequest $request, $portfolioId, $stockId)
    {
        $portfolio = $this->findPortfolio($request, $portfolioId);
        $stock = Stock::findOrFail((int) $stockId);

        $target = PositionTarget::updateOrCreate(
            ['portfolio_id' => $portfolio->id, 'stock_id' => $stock->id],
            $request->validated()
        );

        return response()->json($target);
    }

    public function performance(Request $request, $portfolioId, PortfolioValuationService $valuation)
    {
        $portfolio = $this->findPortfolio($request, $portfolioId);
        $history = $valuation->valueHistory($portfolio);

        return response()->json([
            'history' => $history,
            'metrics' => $valuation->performanceMetrics($portfolio, $history),
        ]);
    }

    public function exportCsv(Request $request, $portfolioId, CsvPortfolioExporter $exporter)
    {
        return $this->export($request, $portfolioId, $exporter);
    }

    public function exportPdf(Request $request, $portfolioId, PdfPortfolioExporter $exporter)
    {
        return $this->export($request, $portfolioId, $exporter);
    }

    public function exportExcel(Request $request, $portfolioId, ExcelPortfolioExporter $exporter)
    {
        return $this->export($request, $portfolioId, $exporter);
    }

    private function export(Request $request, $portfolioId, PortfolioExporter $exporter)
    {
        return $exporter->download($this->findPortfolio($request, $portfolioId));
    }

    /**
     * Scoped to the current user so one user can never touch another's
     * portfolio, even by guessing an id. Cast defensively — an invalid or
     * non-numeric id (e.g. a stale/unset id from the frontend) should 404
     * cleanly via findOrFail, not blow up on a strict scalar type hint.
     */
    private function findPortfolio(Request $request, $portfolioId): Portfolio
    {
        return $request->user()->portfolios()->findOrFail((int) $portfolioId);
    }
}
