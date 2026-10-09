<?php

namespace App\Services\Portfolio;

use App\Models\Portfolio;
use App\Models\PositionTarget;
use App\Models\Stock;

/**
 * An advisory trade plan for a BUY signal. This system never buys or sells anything: you trade with your
 * broker and only log it here. So this does not create orders; it answers "if I acted on this signal, what
 * would a sensible plan look like, and is it worth it?" — and says why not when it isn't. A signal alone is
 * not a plan; it has to get through, in order:
 *
 *   1. Signal     — a fresh buy at or above the minimum score
 *   2. Risk       — entry, stop (just under support) and target (nearest resistance) exist, the stop isn't
 *                   too far, and the risk/reward meets the minimum
 *   3. Portfolio  — not already held, room for another position
 *   4. Cash       — enough cash (the balance you keep on the portfolio) for at least one share plus fees
 *   5. Sizing     — shares = the smallest of what the risk budget, the position-size cap and the cash allow
 *
 * Limits come from config/trading.php. Checks stop at the first failure, so `reason` is the one thing to fix.
 * Nothing is stored, apart from setLevelsFromPlan() copying the plan's stop and target onto a holding when
 * you log the buy.
 */
class TradePlanService
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

        // 1. Signal
        $signal = $stock->latestSignal;
        $price = $stock->latestPrice;
        // The indicator row from the signal's own day, so support/resistance
        // match what the signal saw even if a later row exists.
        $indicator = $signal ? $stock->technicalIndicators()->whereDate('trade_date', $signal->trade_date)->first() : null;
        $minScore = (float) config('trading.min_score');

        if (! $signal || $signal->signal !== 'buy') {
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
        $levels = $this->levelsFrom((float) $price->close_price, $indicator);

        if (isset($levels['error'])) {
            return $fail('risk', $levels['error']);
        }

        ['entry' => $entry, 'stop' => $stop, 'target' => $target, 'risk_per_share' => $riskPerShare, 'risk_reward' => $riskReward] = $levels;
        $minRr = (float) config('trading.min_risk_reward');
        $maxStop = (float) config('trading.max_stop_distance_pct');

        if ($levels['stop_distance_pct'] > $maxStop) {
            return $fail('risk', sprintf('Stop %.2f is %.1f%% below entry %.2f; the limit is %s%%.', $stop, $levels['stop_distance_pct'], $entry, $maxStop));
        }
        if ($riskReward < $minRr) {
            return $fail('risk', "Risk/reward {$riskReward} is below the minimum {$minRr} (entry {$entry}, stop {$stop}, target {$target}).");
        }
        $pass('risk', "Entry {$entry}, stop {$stop}, target {$target}: risk/reward {$riskReward} (minimum {$minRr}).");

        // 3. Portfolio
        $holdings = $this->valuation->holdings($portfolio);

        if ($holdings->contains('stock_id', $stock->id)) {
            return $fail('portfolio', "{$stock->symbol} is already held in this portfolio.");
        }
        $maxPositions = (int) config('trading.max_positions');
        if ($holdings->count() >= $maxPositions) {
            return $fail('portfolio', "Already holding the maximum of {$maxPositions} positions.");
        }
        $pass('portfolio', $holdings->count().' of '.$maxPositions.' positions held.');

        // 4. Cash
        $feeRate = (float) config('trading.fee_pct') / 100;
        $cash = (float) $portfolio->cash_balance;

        if ($cash < $entry * (1 + $feeRate)) {
            return $fail('cash', sprintf('Cash %.2f cannot buy even one share at %.2f.', $cash, $entry));
        }
        $pass('cash', sprintf('Cash %.2f.', $cash));

        // 5. Position size: risk-based, then cut down to the size cap and the cash
        $equity = $cash + (float) $holdings->sum(fn ($h) => $h['current_value'] ?? $h['invested']);
        $size = $this->sizer->size($equity, $cash, $entry, $stop);

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
     * When you log a buy, the stock's stop and target from its current levels (support / resistance) become the
     * holding's levels, unless you have already set some — so the sell checks have something to watch without
     * you doing it by hand. Edit them any time with "Set Levels".
     */
    public function setLevelsFromPlan(Portfolio $portfolio, Stock $stock): void
    {
        $price = $stock->latestPrice;
        $indicator = $stock->technicalIndicators()->orderByDesc('trade_date')->first();

        if (! $price || $price->close_price === null || ! $indicator) {
            return;
        }

        $levels = $this->levelsFrom((float) $price->close_price, $indicator);

        if (isset($levels['error'])) {
            return;
        }

        PositionTarget::firstOrCreate(
            ['portfolio_id' => $portfolio->id, 'stock_id' => $stock->id],
            ['stop_loss' => $levels['stop'], 'target_price' => $levels['target'], 'notes' => 'Set from the support / resistance levels when the buy was logged'],
        );
    }

    public function setCash(Portfolio $portfolio, float $amount): Portfolio
    {
        $portfolio->update(['cash_balance' => $amount]);

        return $portfolio;
    }

    /**
     * Entry (the latest close), stop (just under support), target (nearest resistance) and the reward-to-risk
     * they give — or an `error` saying which level is missing.
     *
     * @return array{entry: float, stop: float, target: float, risk_per_share: float, risk_reward: float, stop_distance_pct: float}|array{error: string}
     */
    private function levelsFrom(float $close, $indicator): array
    {
        $entry = round($close, 2);
        $support = $indicator->support_price !== null ? (float) $indicator->support_price : null;
        $resistance = $indicator->resistance_price !== null ? (float) $indicator->resistance_price : null;

        if ($support === null || $support >= $entry) {
            return ['error' => 'No support level below the current price to place a stop under.'];
        }
        if ($resistance === null || $resistance <= $entry) {
            return ['error' => 'No resistance level above the current price to use as a target.'];
        }

        $stop = round($support * (1 - (float) config('trading.stop_buffer_pct') / 100), 2);
        $target = round($resistance, 2);
        $riskPerShare = round($entry - $stop, 2);

        return [
            'entry' => $entry,
            'stop' => $stop,
            'target' => $target,
            'risk_per_share' => $riskPerShare,
            'risk_reward' => round(($target - $entry) / $riskPerShare, 2),
            'stop_distance_pct' => $riskPerShare / $entry * 100,
        ];
    }
}
