<?php

namespace App\GraphQL\Resolvers;

use App\GraphQL\ApiError;
use App\GraphQL\Resolver;
use App\Http\Requests\SavedScreen\StoreSavedScreenRequest;
use App\Http\Requests\Watchlist\AddWatchlistItemRequest;
use App\Http\Requests\Watchlist\SetWatchlistAlertRequest;
use App\Http\Requests\Watchlist\StoreWatchlistRequest;
use App\Services\Mail\EmailLogService;
use App\Services\Watchlists\WatchlistService;

/** Watchlists, saved screens, and the user's email log. */
class WatchlistResolver extends Resolver
{
    public function __construct(private readonly WatchlistService $watchlists) {}

    public function watchlists(): array
    {
        return $this->plain($this->watchlists->forUser($this->user()));
    }

    public function createWatchlist($root, array $args): array
    {
        return $this->plain($this->watchlists->create($this->user(), $this->validated(StoreWatchlistRequest::class, $args)));
    }

    public function addStock($root, array $args): array
    {
        $stockId = (int) $this->validated(AddWatchlistItemRequest::class, ['stock_id' => $args['stock_id'] ?? null])['stock_id'];

        return $this->plain($this->watchlists->addStock($this->user(), $args['watchlist_id'], $stockId));
    }

    public function removeStock($root, array $args): string
    {
        $this->watchlists->removeStock($this->user(), $args['watchlist_id'], $args['stock_id']);

        return 'Removed.';
    }

    public function setAlert($root, array $args): string
    {
        $alert = $this->validated(SetWatchlistAlertRequest::class, array_intersect_key($args, array_flip(['alert_price', 'alert_direction'])));

        return $this->watchlists->setAlert($this->user(), $args['watchlist_id'], $args['stock_id'], $alert)
            ? 'Alert saved.'
            : throw new ApiError('That stock is not on this watchlist.', 404);
    }

    public function savedScreens(): array
    {
        return $this->plain($this->user()->savedScreens()->latest()->get());
    }

    public function createSavedScreen($root, array $args): array
    {
        return $this->plain($this->user()->savedScreens()->create($this->validated(StoreSavedScreenRequest::class, $args)));
    }

    public function deleteSavedScreen($root, array $args): string
    {
        $this->user()->savedScreens()->findOrFail((int) $args['id'])->delete();

        return 'Deleted.';
    }

    public function emailLogs($root, array $args): array
    {
        return $this->plain(app(EmailLogService::class)->recent($args['status'] ?? null, $args['per_page']));
    }
}
