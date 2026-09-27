<?php

namespace App\Services\Portfolio\Export;

use App\Models\Portfolio;
use App\Services\Portfolio\PortfolioValuationService;
use Symfony\Component\HttpFoundation\Response;

class CsvPortfolioExporter implements PortfolioExporter
{
    public function __construct(private readonly PortfolioValuationService $valuation) {}

    public function download(Portfolio $portfolio): Response
    {
        $holdings = $this->valuation->holdings($portfolio);
        $transactions = $portfolio->transactionHistory()->get();

        return response()->streamDownload(function () use ($holdings, $transactions) {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['HOLDINGS']);
            fputcsv($out, PortfolioTables::HOLDINGS_HEADER);
            foreach ($holdings as $holding) {
                fputcsv($out, PortfolioTables::holdingRow($holding));
            }

            fputcsv($out, []);
            fputcsv($out, ['TRANSACTIONS']);
            fputcsv($out, PortfolioTables::TRANSACTIONS_HEADER);
            foreach ($transactions as $tx) {
                fputcsv($out, PortfolioTables::transactionRow($tx));
            }

            fclose($out);
        }, PortfolioTables::filename($portfolio, 'report', 'csv'), ['Content-Type' => 'text/csv']);
    }
}
