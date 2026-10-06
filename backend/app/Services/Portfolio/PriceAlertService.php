<?php

namespace App\Services\Portfolio;

use App\Models\Stock;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The topbar alerts: a user's portfolio holdings whose price crossed the
 * stop-loss/target they set or fell to the trailing stop (SellSignalService), plus watchlist stocks that crossed their alert
 * price. Computed fresh each call — nothing is stored, consistent with how
 * holdings themselves are never persisted.
 */
class PriceAlertService
{
    public function __construct(private readonly SellSignalService $sells) {}

    public function forUser(User $user): Collection
    {
        return $this->portfolioAlerts($user)->concat($this->watchlistAlerts($user))->values();
    }

    private function portfolioAlerts(User $user): Collection
    {
        $alerts = collect();

        $companies = Stock::pluck('company_name', 'id');

        foreach ($user->portfolios()->get() as $portfolio) {
            foreach ($this->sells->forPortfolio($portfolio) as $decision) {
                $rules = array_column($decision['reasons'], 'rule');
                // The topbar only carries price alerts; breakdown / signal reversal show in the Sell check column instead.
                $status = array_intersect(['stop_loss', 'trailing_stop'], $rules) !== []
                    ? 'stop_breached'
                    : (in_array('target', $rules, true) ? 'target_reached' : null);

                if ($status === null) {
                    continue;
                }

                $alerts->push([
                    'kind' => 'portfolio',
                    'portfolio_id' => $portfolio->id,
                    'portfolio_name' => $portfolio->name,
                    'stock_id' => $decision['stock_id'],
                    'symbol' => $decision['symbol'],
                    'company_name' => $companies[$decision['stock_id']] ?? null,
                    'current_price' => $decision['current_price'],
                    // The level that actually protects the position: the higher of the stop-loss and the trailing stop.
                    'stop_loss' => $decision['effective_stop'],
                    'target_price' => $decision['target_price'],
                    'status' => $status,
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
