<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MarketData\MarketReportService;
use Illuminate\Http\Request;

class WatchlistController extends Controller
{
    public function index(Request $request, MarketReportService $reports)
    {
        $watchlists = $request->user()->watchlists()->with(['stocks.latestPrice', 'stocks.latestSignal'])->get();
        $changes = $reports->priceChanges();

        $watchlists->each(function ($watchlist) use ($changes) {
            $watchlist->stocks->each(function ($stock) use ($changes) {
                $stock->change_pct = $changes->get($stock->id)['change_pct'] ?? null;
            });
        });

        return response()->json($watchlists);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $watchlist = $request->user()->watchlists()->create($validated);

        return response()->json($watchlist, 201);
    }

    public function addItem(Request $request, int $watchlistId)
    {
        $watchlist = $request->user()->watchlists()->findOrFail($watchlistId);

        $validated = $request->validate([
            'stock_id' => ['required', 'exists:stocks,id'],
        ]);

        $watchlist->stocks()->syncWithoutDetaching([$validated['stock_id']]);

        return response()->json($watchlist->load('stocks'));
    }

    public function removeItem(Request $request, int $watchlistId, int $stockId)
    {
        $watchlist = $request->user()->watchlists()->findOrFail($watchlistId);
        $watchlist->stocks()->detach($stockId);

        return response()->json(['message' => 'Removed.']);
    }
}
