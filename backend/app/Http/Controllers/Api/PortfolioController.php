<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PositionTarget;
use App\Models\Stock;
use App\Services\Portfolio\PortfolioValuationService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
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

    /**
     * Sets or clears the stop-loss / take-profit levels an investor wants
     * watched for one holding. Independent of the transaction ledger —
     * upsert semantics, so this can be set, edited, or cleared (nulls) at
     * any time without touching buy/sell history.
     */
    public function setTarget(Request $request, $portfolioId, $stockId)
    {
        $portfolio = $this->findPortfolio($request, $portfolioId);
        $stock = Stock::findOrFail((int) $stockId);

        $validated = $request->validate([
            'stop_loss' => ['nullable', 'numeric', 'min:0'],
            'target_price' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $target = PositionTarget::updateOrCreate(
            ['portfolio_id' => $portfolio->id, 'stock_id' => $stock->id],
            $validated
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
            fputcsv($out, ['Symbol', 'Company', 'Quantity', 'Avg Cost', 'Invested', 'Current Price', 'Current Value', 'Unrealized P&L', 'Unrealized P&L %', 'Stop Loss', 'Target Price', 'Status']);
            foreach ($holdings as $h) {
                fputcsv($out, [
                    $h['symbol'], $h['company_name'], $h['quantity'], $h['avg_cost'], $h['invested'],
                    $h['current_price'], $h['current_value'], $h['unrealized_pnl'], $h['unrealized_pnl_pct'],
                    $h['stop_loss'], $h['target_price'], $h['position_status'],
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

    public function exportPdf(Request $request, $portfolioId, PortfolioValuationService $valuation)
    {
        $portfolio = $this->findPortfolio($request, $portfolioId);
        $summary = $valuation->summary($portfolio);
        $holdings = $valuation->holdings($portfolio);
        $realized = $valuation->realizedPnl($portfolio);

        $html = $this->statementHtml($portfolio, $summary, $holdings, $realized);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = str($portfolio->name)->slug()->value().'-statement-'.now()->toDateString().'.pdf';

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function exportExcel(Request $request, $portfolioId, PortfolioValuationService $valuation)
    {
        $portfolio = $this->findPortfolio($request, $portfolioId);
        $holdings = $valuation->holdings($portfolio);
        $transactions = $portfolio->transactions()
            ->with('stock:id,symbol,company_name')
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->get();

        $spreadsheet = new Spreadsheet();

        $holdingsSheet = $spreadsheet->getActiveSheet();
        $holdingsSheet->setTitle('Holdings');
        $holdingsSheet->fromArray(
            ['Symbol', 'Company', 'Quantity', 'Avg Cost', 'Invested', 'Current Price', 'Current Value', 'Unrealized P&L', 'Unrealized P&L %', 'Stop Loss', 'Target Price', 'Status'],
            null,
            'A1'
        );
        $row = 2;
        foreach ($holdings as $h) {
            $holdingsSheet->fromArray([
                $h['symbol'], $h['company_name'], $h['quantity'], $h['avg_cost'], $h['invested'],
                $h['current_price'], $h['current_value'], $h['unrealized_pnl'], $h['unrealized_pnl_pct'],
                $h['stop_loss'], $h['target_price'], $h['position_status'],
            ], null, "A{$row}");
            $row++;
        }

        $txSheet = $spreadsheet->createSheet();
        $txSheet->setTitle('Transactions');
        $txSheet->fromArray(['Date', 'Symbol', 'Type', 'Quantity', 'Price', 'Fees', 'Notes'], null, 'A1');
        $row = 2;
        foreach ($transactions as $tx) {
            $txSheet->fromArray([
                $tx->transaction_date->toDateString(), $tx->stock?->symbol, $tx->type,
                $tx->quantity, (float) $tx->price, (float) $tx->fees, $tx->notes,
            ], null, "A{$row}");
            $row++;
        }

        $filename = str($portfolio->name)->slug()->value().'-statement-'.now()->toDateString().'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function statementHtml($portfolio, array $summary, $holdings, $realized): string
    {
        $rows = fn ($items, $cells) => collect($items)->map(fn ($item) => '<tr>'.implode('', array_map(fn ($c) => '<td>'.e($c($item)).'</td>', $cells)).'</tr>')->implode('');

        $holdingsRows = $rows($holdings, [
            fn ($h) => $h['symbol'], fn ($h) => $h['company_name'], fn ($h) => $h['quantity'],
            fn ($h) => number_format((float) $h['avg_cost'], 2), fn ($h) => number_format((float) $h['invested'], 2),
            fn ($h) => $h['current_price'] !== null ? number_format((float) $h['current_price'], 2) : '—',
            fn ($h) => $h['unrealized_pnl'] !== null ? number_format((float) $h['unrealized_pnl'], 2) : '—',
        ]);

        $realizedRows = $rows($realized, [
            fn ($r) => $r['symbol'], fn ($r) => $r['transaction_date'], fn ($r) => $r['quantity'],
            fn ($r) => number_format((float) $r['sell_price'], 2), fn ($r) => number_format((float) $r['realized_pnl'], 2),
        ]);

        $generatedAt = now()->toDayDateTimeString();

        return <<<HTML
        <html>
        <head><style>
            body { font-family: sans-serif; font-size: 11px; color: #0f172a; }
            h1 { font-size: 18px; margin-bottom: 2px; }
            h2 { font-size: 14px; margin-top: 24px; border-bottom: 1px solid #cbd5e1; padding-bottom: 4px; }
            table { width: 100%; border-collapse: collapse; margin-top: 8px; }
            th, td { border: 1px solid #e2e8f0; padding: 5px 8px; text-align: left; }
            th { background: #f1f5f9; }
            .summary td { border: none; padding: 3px 8px; }
            .muted { color: #64748b; font-size: 10px; }
        </style></head>
        <body>
            <h1>{$portfolio->name} — Statement</h1>
            <p class="muted">Generated {$generatedAt}. Not financial advice.</p>

            <table class="summary">
                <tr><td><strong>Total Invested</strong></td><td>Rs. {$summary['total_invested']}</td></tr>
                <tr><td><strong>Current Value</strong></td><td>Rs. {$summary['current_value']}</td></tr>
                <tr><td><strong>Unrealized P&L</strong></td><td>Rs. {$summary['unrealized_pnl']}</td></tr>
                <tr><td><strong>Realized P&L</strong></td><td>Rs. {$summary['realized_pnl']}</td></tr>
                <tr><td><strong>Total P&L</strong></td><td>Rs. {$summary['total_pnl']}</td></tr>
            </table>

            <h2>Holdings</h2>
            <table>
                <thead><tr><th>Symbol</th><th>Company</th><th>Qty</th><th>Avg Cost</th><th>Invested</th><th>Current Price</th><th>Unrealized P&L</th></tr></thead>
                <tbody>{$holdingsRows}</tbody>
            </table>

            <h2>Realized Gains / Losses</h2>
            <table>
                <thead><tr><th>Symbol</th><th>Date</th><th>Qty</th><th>Sell Price</th><th>Realized P&L</th></tr></thead>
                <tbody>{$realizedRows}</tbody>
            </table>
        </body>
        </html>
        HTML;
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
