<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ScrapeLog;
use App\Models\Stock;
use App\Services\MarketData\NepalStockScraperService;
use App\Services\MarketData\RecalculationPipeline;
use App\Services\MarketData\SharesansarScraperService;
use Illuminate\Http\Request;
use Throwable;

class ScrapeController extends Controller
{
    public function run(SharesansarScraperService $scraper, RecalculationPipeline $pipeline)
    {
        try {
            $result = $scraper->scrape();
        } catch (Throwable $e) {
            return response()->json(['message' => 'Scrape failed: '.$e->getMessage()], 502);
        }

        $stocks = Stock::whereIn('id', $result['affected_stock_ids'])->get();
        $pipeline->runForMany($stocks);

        return response()->json($result);
    }

    public function runNepse(NepalStockScraperService $scraper, RecalculationPipeline $pipeline)
    {
        try {
            $result = $scraper->scrape();
        } catch (Throwable $e) {
            return response()->json(['message' => 'NEPSE official scrape failed: '.$e->getMessage()], 502);
        }

        $stocks = Stock::whereIn('id', $result['affected_stock_ids'])->get();
        $pipeline->runForMany($stocks);

        return response()->json($result);
    }

    public function logs(Request $request)
    {
        $limit = (int) $request->query('limit', 20);

        return response()->json(ScrapeLog::latest('created_at')->limit($limit)->get());
    }
}
