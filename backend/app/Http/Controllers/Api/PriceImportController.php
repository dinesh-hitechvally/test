<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stock\ImportPricesCsvRequest;
use App\Services\DataSources\Csv\CsvPriceImportService;

/**
 * Daily prices uploaded as a CSV file. A multipart upload, so this stays a
 * plain HTTP route — everything else the SPA sends goes through /graphql.
 */
class PriceImportController extends Controller
{
    public function __invoke(ImportPricesCsvRequest $request, CsvPriceImportService $importer)
    {
        return response()->json($importer->import($request->validated('file'), $request->validated('symbol')));
    }
}
