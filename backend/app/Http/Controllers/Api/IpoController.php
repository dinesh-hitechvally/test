<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IpoListing;
use App\Services\MarketData\ShareSansarIpoService;
use Throwable;

class IpoController extends Controller
{
    public function index()
    {
        $listings = IpoListing::orderByDesc('opening_date')->get();

        return response()->json([
            'open' => $listings->where('stage', 'open')->values(),
            'upcoming' => $listings->where('stage', 'upcoming')->values(),
        ]);
    }

    public function sync(ShareSansarIpoService $service)
    {
        try {
            $result = $service->scrape();
        } catch (Throwable $e) {
            return response()->json(['message' => 'IPO sync failed: '.$e->getMessage()], 502);
        }

        return response()->json($result);
    }
}
