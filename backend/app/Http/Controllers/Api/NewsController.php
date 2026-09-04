<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NewsArticle;
use App\Services\MarketData\ShareSansarNewsService;
use Illuminate\Http\Request;
use Throwable;

class NewsController extends Controller
{
    public function index(Request $request)
    {
        $limit = (int) $request->query('limit', 50);

        return response()->json(
            NewsArticle::orderByDesc('published_date')->orderByDesc('id')->limit($limit)->get()
        );
    }

    public function sync(ShareSansarNewsService $service)
    {
        try {
            $result = $service->scrape();
        } catch (Throwable $e) {
            return response()->json(['message' => 'News sync failed: '.$e->getMessage()], 502);
        }

        return response()->json($result);
    }
}
