<?php

namespace App\Services\Portfolio\Export;

use App\Models\Portfolio;
use App\Services\Portfolio\PortfolioValuationService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;

class ExcelPortfolioExporter implements PortfolioExporter
{
    public function __construct(private readonly PortfolioValuationService $valuation) {}

    public function download(Portfolio $portfolio): Response
    {
        $holdings = collect($this->valuation->holdings($portfolio))->map(fn ($h) => PortfolioTables::holdingRow($h));
        $transactions = $portfolio->transactionHistory()->get()->map(fn ($tx) => PortfolioTables::transactionRow($tx, numeric: true));

        $spreadsheet = new Spreadsheet();

        $holdingsSheet = $spreadsheet->getActiveSheet();
        $holdingsSheet->setTitle('Holdings');
        $holdingsSheet->fromArray([PortfolioTables::HOLDINGS_HEADER, ...$holdings->all()], null, 'A1');

        $txSheet = $spreadsheet->createSheet();
        $txSheet->setTitle('Transactions');
        $txSheet->fromArray([PortfolioTables::TRANSACTIONS_HEADER, ...$transactions->all()], null, 'A1');

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, PortfolioTables::filename($portfolio, 'statement', 'xlsx'), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
