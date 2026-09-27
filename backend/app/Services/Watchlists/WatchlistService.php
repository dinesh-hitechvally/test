<?php

namespace App\Services\Watchlists;

use App\Models\User;
use App\Models\Watchlist;
use App\Services\Reports\PriceStatisticsService;
use Illuminate\Support\Collection;

/**
 * A user's watchlists. Every lookup goes through the user's own watchlists,
 * so one user can never read or change another's (unknown id → 404).
 */
class WatchlistService
{
    public function __construct(private readonly PriceStatisticsService $prices) {}

    /** The user's watchlists with each stock's latest price, signal and today's change_pct. */
    public function forUser(User $user): Collection
    {
        $watchlists = $user->watchlists()->with(['stocks.latestPrice', 'stocks.latestSignal'])->get();
        $this->prices->withChangePct($watchlists->flatMap->stocks);

        return $watchlists;
    }

    public function create(User $user, array $data): Watchlist
    {
        return $user->watchlists()->create($data);
    }

    public function addStock(User $user, int $watchlistId, int $stockId): Watchlist
    {
        $watchlist = $user->watchlists()->findOrFail($watchlistId);
        $watchlist->stocks()->syncWithoutDetaching([$stockId]);

        return $watchlist->load('stocks');
    }

    public function removeStock(User $user, int $watchlistId, int $stockId): void
    {
        $user->watchlists()->findOrFail($watchlistId)->stocks()->detach($stockId);
    }

    /**
     * Sets or clears a price alert on one watchlist item — independent of the
     * portfolio stop-loss/target alerts, since a watched stock isn't
     * necessarily owned. False if the stock isn't on that watchlist.
     */
    public function setAlert(User $user, int $watchlistId, int $stockId, array $alert): bool
    {
        $watchlist = $user->watchlists()->findOrFail($watchlistId);

        if (! $watchlist->stocks()->where('stocks.id', $stockId)->exists()) {
            return false;
        }

        $watchlist->stocks()->updateExistingPivot($stockId, $alert);

        return true;
    }
}
