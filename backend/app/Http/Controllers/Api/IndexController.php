<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DataSources\NepalStock\NepalStockIndexService;
use App\Services\Reports\IndexReportService;
use Illuminate\Http\Request;
use Throwable;

class IndexController extends Controller
{
    public function index(Request $request, IndexReportService $indices)
    {
        return response()->json($indices->history($request->integer('days', 90)));
    }

    public function sync(NepalStockIndexService $service)
    {
        try {
            return response()->json($service->sync());
        } catch (Throwable $e) {
            return response()->json(['message' => 'Index sync failed: '.$e->getMessage()], 502);
        }
    }
}
