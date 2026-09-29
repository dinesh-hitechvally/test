<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Portfolio\Export\CsvPortfolioExporter;
use App\Services\Portfolio\Export\ExcelPortfolioExporter;
use App\Services\Portfolio\Export\PdfPortfolioExporter;
use App\Services\Portfolio\Export\PortfolioExporter;
use App\Services\Portfolio\PortfolioService;
use Illuminate\Http\Request;

/**
 * Portfolio report downloads. File responses, so these stay plain HTTP
 * routes — everything else the SPA reads goes through /graphql.
 */
class PortfolioExportController extends Controller
{
    public function __construct(private readonly PortfolioService $portfolios) {}

    public function csv(Request $request, $portfolioId, CsvPortfolioExporter $exporter)
    {
        return $this->export($request, $portfolioId, $exporter);
    }

    public function pdf(Request $request, $portfolioId, PdfPortfolioExporter $exporter)
    {
        return $this->export($request, $portfolioId, $exporter);
    }

    public function excel(Request $request, $portfolioId, ExcelPortfolioExporter $exporter)
    {
        return $this->export($request, $portfolioId, $exporter);
    }

    private function export(Request $request, $portfolioId, PortfolioExporter $exporter)
    {
        return $exporter->download($this->portfolios->find($request->user(), $portfolioId));
    }
}
