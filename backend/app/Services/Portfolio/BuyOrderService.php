<?php

namespace App\Services\Portfolio;

use App\Models\BuyOrder;
use App\Models\Portfolio;
use App\Models\PositionTarget;
use App\Models\Stock;
use Illuminate\Support\Facades\DB;

/**
 * Turns a BUY signal into a buy order — or says why not. A signal alone
 * never buys; it has to get through, in order:
 *
 *   1. Signal     — a fresh buy / strong_buy at or above the minimum score
 *   2. Risk       — entry, stop (just under support) and target (nearest
 *                   resistance) exist, the stop isn't too far, and the
 *                   risk/reward meets the minimum
 *   3. Portfolio  — not already held or ordered, room for another position
 *   4. Cash       — enough free cash (cash balance minus pending orders)
 *   5. Sizing     — shares = the smallest of what the risk budget, the
 *                   position-size cap and the free cash allow
 *
 * Limits come from config/trading.php. evaluate() only reports (apart from
 * lapsing stale pending orders); place() stores the order. Checks stop at the first failure, so `reason` is the
 * one thing to fix.
 */
class BuyOrderService
{
    public function __construct(
        private readonly PortfolioValuationService $valuation,
        private readonly PositionSizer $sizer,
    ) {}

    /**
     * @return array{approved: bool, reason: ?string, checks: list<array{name: string, passed: bool, detail: string}>, plan: ?array<string, mixed>}
     */
    public function evaluate(Portfolio $portfolio, Stock $stock): array
    {
        $checks = [];
        $fail = function (string $name, string $detail) use (&$checks): array {
            $checks[] = ['name' => $name, 'passed' => false, 'detail' => $detail];

            return ['approved' => false, 'reason' => $detail, 'checks' => $checks, 'plan' => null];
        };
        $pass = function (string $name, string $detail) use (&$checks): void {
            $checks[] = ['name' => $name, 'passed' => true, 'detail' => $detail];
        };

        $this->expireStaleOrders($portfolio);

        // 1. Signal
        $signal = $stock->latestSignal;
        $price = $stock->latestPrice;
        // The indicator row from the signal's own day, so support/resistance
        // match what the signal saw even if a later row exists.
        $indicator = $signal ? $stock->technicalIndicators()->whereDate('trade_date', $signal->trade_date)->first() : null;
        $minScore = (float) config('trading.min_score');

        if (! $signal || ! in_array($signal->signal, ['buy', 'strong_buy'], true)) {
            return $fail('signal', 'Latest signal is '.($signal->signal ?? 'missing').', not a buy.');
        }
        if ((float) $signal->score < $minScore) {
            return $fail('signal', "Signal score {$signal->score} is below the minimum {$minScore}.");
        }
        if (! $price || $price->close_price === null || ! $indicator) {
            return $fail('signal', 'No price or indicator data yet.');
        }
        if (abs($signal->trade_date->diffInDays($price->trade_date)) > (int) config('trading.signal_max_age_days')) {
            return $fail('signal', "Signal from {$signal->trade_date->toDateString()} is stale; latest price is {$price->trade_date->toDateString()}.");
        }
        $pass('signal', "{$signal->signal}, score {$signal->score}.");

        // 2. Risk: entry, stop, target, reward-to-risk
        $entry = round((float) $price->close_price, 2);
        $support = $indicator->support_price !== null ? (float) $indicator->support_price : null;
        $resistance = $indicator->resistance_price !== null ? (float) $indicator->resistance_price : null;

        if ($support === null || $support >= $entry) {
            return $fail('risk', 'No support level below the current price to place a stop under.');
        }
        if ($resistance === null || $resistance <= $entry) {
            return $fail('risk', 'No resistance level above the current price to use as a target.');
        }

        $stop = round($support * (1 - (float) config('trading.stop_buffer_pct') / 100), 2);
        $target = round($resistance, 2);
        $riskPerShare = round($entry - $stop, 2);
        $stopDistancePct = $riskPerShare / $entry * 100;
        $riskReward = round(($target - $entry) / $riskPerShare, 2);
        $minRr = (float) config('trading.min_risk_reward');
        $maxStop = (float) config('trading.max_stop_distance_pct');

        if ($stopDistancePct > $maxStop) {
            return $fail('risk', sprintf('Stop %.2f is %.1f%% below entry %.2f; the limit is %s%%.', $stop, $stopDistancePct, $entry, $maxStop));
        }
        if ($riskReward < $minRr) {
            return $fail('risk', "Risk/reward {$riskReward} is below the minimum {$minRr} (entry {$entry}, stop {$stop}, target {$target}).");
        }
        $pass('risk', "Entry {$entry}, stop {$stop}, target {$target}: risk/reward {$riskReward} (minimum {$minRr}).");

        // 3. Portfolio
        $holdings = $this->valuation->holdings($portfolio);
        $pending = BuyOrder::where('portfolio_id', $portfolio->id)->where('status', 'pending')->get();

        if ($holdings->contains('stock_id', $stock->id)) {
            return $fail('portfolio', "{$stock->symbol} is already held in this portfolio.");
        }
        if ($pending->contains('stock_id', $stock->id)) {
            return $fail('portfolio', "A buy order for {$stock->symbol} is already pending.");
        }
        $maxPositions = (int) config('trading.max_positions');
        if ($holdings->count() + $pending->count() >= $maxPositions) {
            return $fail('portfolio', "Already at the maximum of {$maxPositions} positions (holdings + pending orders).");
        }
        $pass('portfolio', ($holdings->count() + $pending->count()).' of '.$maxPositions.' positions used.');

        // 4. Cash
        $feeRate = (float) config('trading.fee_pct') / 100;
        $cash = (float) $portfolio->cash_balance;
        $reserved = (float) $pending->sum(fn ($o) => (float) $o->position_value + (float) $o->fees);
        $freeCash = $cash - $reserved;

        if ($freeCash < $entry * (1 + $feeRate)) {
            return $fail('cash', sprintf('Free cash %.2f (balance %.2f, %.2f reserved by pending orders) cannot buy even one share at %.2f.', $freeCash, $cash, $reserved, $entry));
        }
        $pass('cash', sprintf('Free cash %.2f.', $freeCash));

        // 5. Position size: risk-based, then cut down to the size cap and free cash
        $equity = $cash + (float) $holdings->sum(fn ($h) => $h['current_value'] ?? $h['invested']);
        $size = $this->sizer->size($equity, $freeCash, $entry, $stop);

        if ($size['quantity'] < 1) {
            return $fail('sizing', "Position size rounds to 0 shares (by risk {$size['by_risk']}, by size cap {$size['by_cap']}, by cash {$size['by_cash']}).");
        }
        $pass('sizing', "{$size['quantity']} shares, limited by {$size['limited_by']} (by risk {$size['by_risk']}, by size cap {$size['by_cap']}, by cash {$size['by_cash']}).");

        return [
            'approved' => true,
            'reason' => null,
            'checks' => $checks,
            'plan' => [
                'stock_id' => $stock->id,
                'signal_id' => $signal->id,
                'trade_date' => $signal->trade_date->toDateString(),
                'signal_score' => (float) $signal->score,
                'quantity' => $size['quantity'],
                'entry_price' => $entry,
                'stop_loss' => $stop,
                'target_price' => $target,
                'risk_per_share' => $riskPerShare,
                'risk_amount' => $size['risk_amount'],
                'position_value' => $size['position_value'],
                'fees' => $size['fees'],
                'risk_reward' => $riskReward,
            ],
        ];
    }

    /**
     * Locks the portfolio row for the duration, so two simultaneous requests
     * can't both pass the cash / position-count checks and over-commit.
     *
     * @throws BuyOrderRejectedException when any check fails
     */
    public function place(Portfolio $portfolio, Stock $stock): BuyOrder
    {
        return DB::transaction(function () use ($portfolio, $stock) {
            $portfolio = Portfolio::whereKey($portfolio->id)->lockForUpdate()->firstOrFail();
            $result = $this->evaluate($portfolio, $stock);

            if (! $result['approved']) {
                throw new BuyOrderRejectedException($result['reason']);
            }

            return $portfolio->buyOrders()->create([...$result['plan'], 'status' => 'pending'])->load('stock:id,symbol,company_name');
        });
    }

    /**
     * A buy transaction for the stock fulfils its pending order, which stops
     * the order reserving cash and counting as a position on top of the
     * holding it became. The order's stop and target become the position's
     * levels (unless the user already set some), so the sell rules watch them.
     * Called by PortfolioService when a buy is recorded.
     */
    public function markExecuted(Portfolio $portfolio, int $stockId): void
    {
        foreach ($portfolio->buyOrders()->where('stock_id', $stockId)->where('status', 'pending')->get() as $order) {
            PositionTarget::firstOrCreate(
                ['portfolio_id' => $portfolio->id, 'stock_id' => $stockId],
                ['stop_loss' => $order->stop_loss, 'target_price' => $order->target_price, 'notes' => "From buy order #{$order->id}"],
            );
            $order->update(['status' => 'executed']);
        }
    }

    /** Pending orders older than trading.order_valid_days lapse, freeing their cash and slot. */
    private function expireStaleOrders(Portfolio $portfolio): void
    {
        $portfolio->buyOrders()
            ->where('status', 'pending')
            ->where('created_at', '<', now()->subDays((int) config('trading.order_valid_days')))
            ->update(['status' => 'expired']);
    }

    public function cancel(Portfolio $portfolio, mixed $orderId): BuyOrder
    {
        $order = $portfolio->buyOrders()->where('status', 'pending')->findOrFail((int) $orderId);
        $order->update(['status' => 'cancelled']);

        return $order;
    }

    public function setCash(Portfolio $portfolio, float $amount): Portfolio
    {
        $portfolio->update(['cash_balance' => $amount]);

        return $portfolio;
    }
}
