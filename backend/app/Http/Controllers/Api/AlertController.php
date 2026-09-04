<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Portfolio\PortfolioValuationService;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    /**
     * Scans every portfolio the user owns for holdings whose price has
     * crossed a stop-loss or target level they set. Computed fresh from
     * PortfolioValuationService::holdings() each call — nothing is stored,
     * consistent with how holdings themselves are never persisted.
     */
    public function index(Request $request, PortfolioValuationService $valuation)
    {
        $portfolios = $request->user()->portfolios()->get();

        $alerts = collect();

        foreach ($portfolios as $portfolio) {
            foreach ($valuation->holdings($portfolio) as $holding) {
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

        $watchlists = $request->user()->watchlists()->with('stocks.latestPrice')->get();

        foreach ($watchlists as $watchlist) {
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

        return response()->json($alerts->values());
    }
}
