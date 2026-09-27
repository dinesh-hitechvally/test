<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Watchlist\AddWatchlistItemRequest;
use App\Http\Requests\Watchlist\SetWatchlistAlertRequest;
use App\Http\Requests\Watchlist\StoreWatchlistRequest;
use App\Services\Watchlists\WatchlistService;
use Illuminate\Http\Request;

class WatchlistController extends Controller
{
    public function __construct(private readonly WatchlistService $watchlists) {}

    public function index(Request $request)
    {
        return response()->json($this->watchlists->forUser($request->user()));
    }

    public function store(StoreWatchlistRequest $request)
    {
        return response()->json($this->watchlists->create($request->user(), $request->validated()), 201);
    }

    public function addItem(AddWatchlistItemRequest $request, int $watchlistId)
    {
        return response()->json($this->watchlists->addStock($request->user(), $watchlistId, (int) $request->validated('stock_id')));
    }

    public function removeItem(Request $request, int $watchlistId, int $stockId)
    {
        $this->watchlists->removeStock($request->user(), $watchlistId, $stockId);

        return response()->json(['message' => 'Removed.']);
    }

    public function setAlert(SetWatchlistAlertRequest $request, int $watchlistId, int $stockId)
    {
        return $this->watchlists->setAlert($request->user(), $watchlistId, $stockId, $request->validated())
            ? response()->json(['message' => 'Alert saved.'])
            : response()->json(['message' => 'That stock is not on this watchlist.'], 404);
    }
}
