<?php

namespace App\Services\Portfolio;

use App\Models\DailyPrice;
use App\Models\Portfolio;
use App\Models\Signal;
use App\Models\TechnicalIndicator;
use Illuminate\Support\Collection;

/**
 * Decides, for every holding in a portfolio, whether it should be sold and
 * why. A holding can trigger several reasons at once; all are reported, the
 * first in this order is the primary one:
 *
 *   stop_loss        price <= the stop set on the position
 *   trailing_stop    price <= highest close since entry minus a fixed distance
 *   target           price >= the target set on the position
 *   breakdown        close under SMA50, on rising volume, through support
 *   signal_reversal  the latest signal is sell
 *
 * Trailing stop: the distance is trading.trailing_stop_pct of the average
 * cost (6% of 820 ≈ 49). It switches on once the stock has risen far enough
 * that the stop sits at or above cost (≈ 869 for a 820 buy), then follows the
 * highest close: peak 900 → stop ≈ 851, peak 950 → ≈ 901, peak 980 → ≈ 931.
 * It is worked out from price history each time, so it can only ever move up
 * and nothing about it is stored.
 *
 * Read-only: it recommends, it never sells. A position's stop and target
 * come from the levels set on it (PositionTarget), which a filled buy order
 * pre-fills.
 */
class SellSignalService
{
    public function __construct(private readonly PortfolioValuationService $valuation) {}

    /** @return Collection<int, array<string, mixed>> one entry per holding */
    public function forPortfolio(Portfolio $portfolio): Collection
    {
        return $this->valuation->holdings($portfolio)
            ->map(fn (array $holding) => $this->evaluate($portfolio, $holding))
            ->values();
    }

    /**
     * @param  array<string, mixed>  $holding  one row of PortfolioValuationService::holdings()
     * @return array<string, mixed>
     */
    public function evaluate(Portfolio $portfolio, array $holding): array
    {
        $price = $holding['current_price'];
        $avgCost = (float) $holding['avg_cost'];
        $stopLoss = $holding['stop_loss'];
        $target = $holding['target_price'];
        $reasons = [];

        if ($price === null) {
            return $this->result($holding, null, null, [], 'No price yet — cannot judge this position.');
        }

        $highest = $this->highestCloseSinceEntry($portfolio, (int) $holding['stock_id']) ?? $price;
        $highest = max($highest, $price);
        $trailing = $this->trailingStop($avgCost, $highest);

        // A. Stop loss
        if ($stopLoss !== null && $price <= $stopLoss) {
            $reasons[] = ['rule' => 'stop_loss', 'detail' => sprintf('Price %.2f is at or below the stop loss %.2f.', $price, $stopLoss)];
        }

        // E. Trailing stop
        if ($trailing !== null && $price <= $trailing) {
            $reasons[] = ['rule' => 'trailing_stop', 'detail' => sprintf('Price %.2f fell to the trailing stop %.2f (highest close since entry %.2f).', $price, $trailing, $highest)];
        }

        // B. Target reached
        if ($target !== null && $price >= $target) {
            $reasons[] = ['rule' => 'target', 'detail' => sprintf('Price %.2f reached the target %.2f.', $price, $target)];
        }

        // D. Technical breakdown
        if ($breakdown = $this->breakdown((int) $holding['stock_id'])) {
            $reasons[] = ['rule' => 'breakdown', 'detail' => $breakdown];
        }

        // C. Signal reversal
        if ($reversal = $this->signalReversal((int) $holding['stock_id'])) {
            $reasons[] = ['rule' => 'signal_reversal', 'detail' => $reversal];
        }

        return $this->result($holding, $trailing, $highest, $reasons);
    }

    /**
     * The trailing stop for a position bought at $avgCost whose highest close
     * has been $highest; null until the stop would be at or above cost.
     */
    public function trailingStop(float $avgCost, float $highest): ?float
    {
        $distance = $avgCost * (float) config('trading.trailing_stop_pct') / 100;

        if ($distance <= 0 || $highest - $distance < $avgCost) {
            return null;
        }

        return round($highest - $distance, 2);
    }

    /** Close under SMA50 + volume above its average and above yesterday's + closed through yesterday's support. */
    private function breakdown(int $stockId): ?string
    {
        [$today, $yesterday] = TechnicalIndicator::where('stock_id', $stockId)->orderByDesc('trade_date')->limit(2)->get()->all() + [null, null];
        $prices = DailyPrice::where('stock_id', $stockId)->orderByDesc('trade_date')->limit(2)->get();

        if (! $today || ! $yesterday || $prices->count() < 2 || $today->sma_50 === null || $yesterday->support_price === null) {
            return null;
        }

        $close = (float) $prices[0]->close_price;
        $sma50 = (float) $today->sma_50;
        $support = (float) $yesterday->support_price;
        $volumeUp = $today->volume_ratio !== null
            && (float) $today->volume_ratio > (float) config('trading.breakdown_min_volume_ratio')
            && (int) $prices[0]->volume > (int) $prices[1]->volume;

        if ($close < $sma50 && $volumeUp && $close < $support) {
            return sprintf('Close %.2f is below SMA50 %.2f and through support %.2f on rising volume (%.1fx average).', $close, $sma50, $support, (float) $today->volume_ratio);
        }

        return null;
    }

    private function signalReversal(int $stockId): ?string
    {
        [$current, $previous] = Signal::where('stock_id', $stockId)->orderByDesc('trade_date')->limit(2)->get()->all() + [null, null];

        if (! $current || $current->signal !== 'sell') {
            return null;
        }

        return 'Signal is now '.$current->signal.($previous ? " (was {$previous->signal})." : '.');
    }

    /** Highest close since the current position was opened (the last time it went from nothing held to something). */
    private function highestCloseSinceEntry(Portfolio $portfolio, int $stockId): ?float
    {
        $qty = 0;
        $start = null;

        foreach ($portfolio->transactions()->where('stock_id', $stockId)->orderBy('transaction_date')->orderBy('id')->get() as $tx) {
            if ($tx->type === 'buy') {
                $start ??= $tx->transaction_date->toDateString();
                $qty += (int) $tx->quantity;
            } else {
                $qty -= (int) $tx->quantity;

                if ($qty <= 0) {
                    $qty = 0;
                    $start = null;
                }
            }
        }

        if ($start === null) {
            return null;
        }

        $max = DailyPrice::where('stock_id', $stockId)->whereDate('trade_date', '>=', $start)->max('close_price');

        return $max !== null ? (float) $max : null;
    }

    /**
     * @param  list<array{rule: string, detail: string}>  $reasons
     * @return array<string, mixed>
     */
    private function result(array $holding, ?float $trailing, ?float $highest, array $reasons, ?string $note = null): array
    {
        $stopLoss = $holding['stop_loss'];

        return [
            'stock_id' => $holding['stock_id'],
            'symbol' => $holding['symbol'],
            'quantity' => $holding['quantity'],
            'avg_cost' => $holding['avg_cost'],
            'current_price' => $holding['current_price'],
            'action' => $reasons === [] ? 'hold' : 'sell',
            'primary_reason' => $reasons[0]['rule'] ?? null,
            'reasons' => $reasons,
            'note' => $note,
            'stop_loss' => $stopLoss,
            'trailing_stop' => $trailing,
            // The level that actually protects the position: the higher of the two stops.
            'effective_stop' => max(array_filter([$stopLoss, $trailing], fn ($v) => $v !== null) ?: [null]),
            'target_price' => $holding['target_price'],
            'highest_close' => $highest,
        ];
    }
}
