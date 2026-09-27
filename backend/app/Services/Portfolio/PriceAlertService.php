<?php

namespace App\Services\Portfolio;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The topbar alerts: a user's portfolio holdings whose price crossed the
 * stop-loss/target they set, plus watchlist stocks that crossed their alert
 * price. Computed fresh each call — nothing is stored, consistent with how
 * holdings themselves are never persisted.
 */
class PriceAlertService
{
    public function __construct(private readonly PortfolioValuationService $valuation) {}

    public function forUser(User $user): Collection
    {
        return $this->portfolioAlerts($user)->concat($this->watchlistAlerts($user))->values();
    }

    private function portfolioAlerts(User $user): Collection
    {
        $alerts = collect();

        foreach ($user->portfolios()->get() as $portfolio) {
            foreach ($this->valuation->holdings($portfolio) as $holding) {
                if (! in_array($holding['position_status'], ['stop_breached', 'target_reached'], true)) {
                    continue;
                }

                $alerts->push([
                    'kind' => 'portfolio',
                    'portfolio_id' => $portfolio->id,
                    'portfolio_name' => $portfolio->name,
                    'stock_id' => $holding['stock_id'],
                    'symbol' => $holding['symbol'],
                    'company_name' => $holding['company_name'],
                    'current_price' => $holding['current_price'],
                    'stop_loss' => $holding['stop_loss'],
                    'target_price' => $holding['target_price'],
                    'status' => $holding['position_status'],
                ]);
            }
        }

        return $alerts;
    }

    private function watchlistAlerts(User $user): Collection
    {
        $alerts = collect();

        foreach ($user->watchlists()->with('stocks.latestPrice')->get() as $watchlist) {
            foreach ($watchlist->stocks as $stock) {
                $alertPrice = $stock->pivot->alert_price;
                $direction = $stock->pivot->alert_direction;
                $currentPrice = $stock->latestPrice?->close_price !== null ? (float) $stock->latestPrice->close_price : null;

                if ($alertPrice === null || $direction === null || $currentPrice === null) {
                    continue;
                }

                $triggered = $direction === 'above' ? $currentPrice >= (float) $alertPrice : $currentPrice <= (float) $alertPrice;

                if (! $triggered) {
                    continue;
                }

                $alerts->push([
                    'kind' => 'watchlist',
                    'watchlist_id' => $watchlist->id,
                    'watchlist_name' => $watchlist->name,
                    'stock_id' => $stock->id,
                    'symbol' => $stock->symbol,
                    'company_name' => $stock->company_name,
                    'current_price' => $currentPrice,
                    'alert_price' => (float) $alertPrice,
                    'alert_direction' => $direction,
                ]);
            }
        }

        return $alerts;
    }
}
