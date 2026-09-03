<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $portfolio = $request->user()->portfolios()->create($validated);

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

        $transactions = $portfolio->transactions()
            ->with('stock:id,symbol,company_name')
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->get();

        return response()->json($transactions);
    }

    public function storeTransaction(Request $request, $portfolioId, PortfolioValuationService $valuation)
    {
        $portfolio = $this->findPortfolio($request, $portfolioId);

        $validated = $request->validate([
            'stock_id' => ['required', 'exists:stocks,id'],
            'type' => ['required', 'in:buy,sell'],
            'quantity' => ['required', 'integer', 'min:1'],
            'price' => ['required', 'numeric', 'min:0.01'],
            'fees' => ['nullable', 'numeric', 'min:0'],
            'transaction_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

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

    public function performance(Request $request, $portfolioId, PortfolioValuationService $valuation)
    {
        $portfolio = $this->findPortfolio($request, $portfolioId);

        return response()->json($valuation->valueHistory($portfolio));
    }

    public function exportCsv(Request $request, $portfolioId, PortfolioValuationService $valuation)
    {
        $portfolio = $this->findPortfolio($request, $portfolioId);
        $holdings = $valuation->holdings($portfolio);
        $transactions = $portfolio->transactions()
            ->with('stock:id,symbol,company_name')
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->get();

        $filename = str($portfolio->name)->slug()->value().'-report-'.now()->toDateString().'.csv';

        return response()->streamDownload(function () use ($holdings, $transactions) {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['HOLDINGS']);
            fputcsv($out, ['Symbol', 'Company', 'Quantity', 'Avg Cost', 'Invested', 'Current Price', 'Current Value', 'Unrealized P&L', 'Unrealized P&L %']);
            foreach ($holdings as $h) {
                fputcsv($out, [
                    $h['symbol'], $h['company_name'], $h['quantity'], $h['avg_cost'], $h['invested'],
                    $h['current_price'], $h['current_value'], $h['unrealized_pnl'], $h['unrealized_pnl_pct'],
                ]);
            }

            fputcsv($out, []);
            fputcsv($out, ['TRANSACTIONS']);
            fputcsv($out, ['Date', 'Symbol', 'Type', 'Quantity', 'Price', 'Fees', 'Notes']);
            foreach ($transactions as $tx) {
                fputcsv($out, [
                    $tx->transaction_date->toDateString(), $tx->stock?->symbol, $tx->type,
                    $tx->quantity, $tx->price, $tx->fees, $tx->notes,
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Scoped to the current user so one user can never touch another's
     * portfolio, even by guessing an id. Cast defensively — an invalid or
     * non-numeric id (e.g. a stale/unset id from the frontend) should 404
     * cleanly via findOrFail, not blow up on a strict scalar type hint.
     */
    private function findPortfolio(Request $request, $portfolioId)
    {
        return $request->user()->portfolios()->findOrFail((int) $portfolioId);
    }
}
