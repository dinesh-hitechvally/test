<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IndexSnapshot;
use App\Services\MarketData\NepalStockIndexService;
use Illuminate\Http\Request;
use Throwable;

class IndexController extends Controller
{
    public function index(Request $request)
    {
        $days = (int) $request->query('days', 90);
        $since = now()->subDays($days)->toDateString();

        $rows = IndexSnapshot::where('trade_date', '>=', $since)->orderBy('trade_date')->get();
        $byIndex = $rows->groupBy('index_name');

        $indices = $byIndex->map(function ($group, $name) {
            $latest = $group->last();

            return [
                'index_name' => $name,
                'latest' => $latest,
                'history' => $group->map(fn ($r) => [
                    'trade_date' => $r->trade_date->toDateString(),
                    'close' => (float) $r->close,
                ])->values(),
            ];
        })->values();

        return response()->json($indices);
    }

    public function sync(NepalStockIndexService $service)
    {
        try {
            $result = $service->sync();
        } catch (Throwable $e) {
            return response()->json(['message' => 'Index sync failed: '.$e->getMessage()], 502);
        }

        return response()->json($result);
    }
}
