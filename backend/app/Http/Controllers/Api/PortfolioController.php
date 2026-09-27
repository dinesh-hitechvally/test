<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Portfolio\SetPositionTargetRequest;
use App\Http\Requests\Portfolio\StorePortfolioRequest;
use App\Http\Requests\Portfolio\StoreTransactionRequest;
use App\Services\Portfolio\Export\CsvPortfolioExporter;
use App\Services\Portfolio\Export\ExcelPortfolioExporter;
use App\Services\Portfolio\Export\PdfPortfolioExporter;
use App\Services\Portfolio\Export\PortfolioExporter;
use App\Services\Portfolio\InvalidTransactionException;
use App\Services\Portfolio\PortfolioService;
use Illuminate\Http\Request;

class PortfolioController extends Controller
{
    public function __construct(private readonly PortfolioService $portfolios) {}

    public function index(Request $request)
    {
        return response()->json($this->portfolios->forUser($request->user()));
    }

    public function store(StorePortfolioRequest $request)
    {
        return response()->json($this->portfolios->create($request->user(), $request->validated()), 201);
    }

    public function show(Request $request, $portfolioId)
    {
        return response()->json($this->portfolios->details($this->portfolios->find($request->user(), $portfolioId)));
    }

    public function transactions(Request $request, $portfolioId)
    {
        return response()->json($this->portfolios->find($request->user(), $portfolioId)->transactionHistory()->get());
    }

    public function storeTransaction(StoreTransactionRequest $request, $portfolioId)
    {
        $portfolio = $this->portfolios->find($request->user(), $portfolioId);

        try {
            return response()->json($this->portfolios->addTransaction($portfolio, $request->validated()), 201);
        } catch (InvalidTransactionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function destroyTransaction(Request $request, $portfolioId, $transactionId)
    {
        $this->portfolios->deleteTransaction($this->portfolios->find($request->user(), $portfolioId), $transactionId);

        return response()->json(['message' => 'Deleted.']);
    }

    public function setTarget(SetPositionTargetRequest $request, $portfolioId, $stockId)
    {
        $portfolio = $this->portfolios->find($request->user(), $portfolioId);

        return response()->json($this->portfolios->setTarget($portfolio, $stockId, $request->validated()));
    }

    public function performance(Request $request, $portfolioId)
    {
        return response()->json($this->portfolios->performance($this->portfolios->find($request->user(), $portfolioId)));
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
        return $exporter->download($this->portfolios->find($request->user(), $portfolioId));
    }
}
