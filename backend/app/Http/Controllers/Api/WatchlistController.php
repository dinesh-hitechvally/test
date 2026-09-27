<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Watchlist\AddWatchlistItemRequest;
use App\Http\Requests\Watchlist\SetWatchlistAlertRequest;
use App\Http\Requests\Watchlist\StoreWatchlistRequest;
use App\Services\Reports\MarketReportService;
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

    public function store(StoreWatchlistRequest $request)
    {
        $watchlist = $request->user()->watchlists()->create($request->validated());

        return response()->json($watchlist, 201);
    }

    public function addItem(AddWatchlistItemRequest $request, int $watchlistId)
    {
        $watchlist = $request->user()->watchlists()->findOrFail($watchlistId);

        $watchlist->stocks()->syncWithoutDetaching([$request->validated('stock_id')]);

        return response()->json($watchlist->load('stocks'));
    }

    public function removeItem(Request $request, int $watchlistId, int $stockId)
    {
        $watchlist = $request->user()->watchlists()->findOrFail($watchlistId);
        $watchlist->stocks()->detach($stockId);

        return response()->json(['message' => 'Removed.']);
    }

    /**
     * Sets or clears a price alert on one watchlist item — independent of
     * the portfolio stop-loss/target alerts, since a watchlist stock isn't
     * necessarily something you own.
     */
    public function setAlert(SetWatchlistAlertRequest $request, int $watchlistId, int $stockId)
    {
        $watchlist = $request->user()->watchlists()->findOrFail($watchlistId);
        $validated = $request->validated();

        if (! $watchlist->stocks()->where('stocks.id', $stockId)->exists()) {
            return response()->json(['message' => 'That stock is not on this watchlist.'], 404);
        }

        $watchlist->stocks()->updateExistingPivot($stockId, $validated);

        return response()->json(['message' => 'Alert saved.']);
    }
}
