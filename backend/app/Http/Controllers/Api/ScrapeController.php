<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ScrapeLog;
use App\Services\DataSources\NepalStock\NepalStockScraperService;
use Illuminate\Http\Request;
use Throwable;

class ScrapeController extends Controller
{
    /** Recalculation of the scraped stocks follows automatically (StockPricesUpdated). */
    public function runNepse(NepalStockScraperService $scraper)
    {
        try {
            $result = $scraper->scrape();
        } catch (Throwable $e) {
            return response()->json(['message' => 'NEPSE official scrape failed: '.$e->getMessage()], 502);
        }

        return response()->json($result);
    }

    public function logs(Request $request)
    {
        $limit = (int) $request->query('limit', 20);

        return response()->json(ScrapeLog::latest('created_at')->limit($limit)->get());
    }
}
