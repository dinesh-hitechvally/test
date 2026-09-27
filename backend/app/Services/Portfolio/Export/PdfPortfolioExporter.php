<?php

namespace App\Services\Portfolio\Export;

use App\Models\Portfolio;
use App\Services\Portfolio\PortfolioValuationService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\HttpFoundation\Response;

class PdfPortfolioExporter implements PortfolioExporter
{
    public function __construct(private readonly PortfolioValuationService $valuation) {}

    public function download(Portfolio $portfolio): Response
    {
        $html = $this->statementHtml(
            $portfolio,
            $this->valuation->summary($portfolio),
            $this->valuation->holdings($portfolio),
            $this->valuation->realizedPnl($portfolio),
        );

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = PortfolioTables::filename($portfolio, 'statement', 'pdf');

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function statementHtml(Portfolio $portfolio, array $summary, $holdings, $realized): string
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
}
