<?php

namespace App\Services\Portfolio;

use App\Models\DailyPrice;
use App\Models\Dividend;
use App\Models\Portfolio;
use App\Models\PositionTarget;
use App\Models\Stock;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Holdings and P&L are never stored — they're derived by replaying a
 * portfolio's immutable transaction ledger in date order, using the
 * weighted-average cost method. This keeps "what you own" always in sync
 * with "what you actually bought/sold," with nothing to drift out of sync.
 */
class PortfolioValuationService
{
    /**
     * @return Collection<int, array{symbol: string, company_name: ?string, quantity: int, avg_cost: float, invested: float, current_price: ?float, current_value: ?float, unrealized_pnl: ?float, unrealized_pnl_pct: ?float}>
     */
    public function holdings(Portfolio $portfolio): Collection
    {
        $replay = $this->replay($portfolio);

        if ($replay === []) {
            return collect();
        }

        $stocks = Stock::with(['latestPrice', 'latestSignal'])->whereIn('id', array_keys($replay))->get()->keyBy('id');
        $targets = PositionTarget::where('portfolio_id', $portfolio->id)->get()->keyBy('stock_id');

        $holdings = collect();

        foreach ($replay as $stockId => $state) {
            if ($state['qty'] <= 0) {
                continue;
            }

            $stock = $stocks->get($stockId);

            if (! $stock) {
                continue;
            }

            $avgCost = $state['total_cost'] / $state['qty'];
            $currentPrice = $stock->latestPrice?->close_price !== null ? (float) $stock->latestPrice->close_price : null;
            $currentValue = $currentPrice !== null ? $state['qty'] * $currentPrice : null;
            $unrealizedPnl = $currentValue !== null ? $currentValue - $state['total_cost'] : null;

            $target = $targets->get($stockId);
            $stopLoss = $target?->stop_loss !== null ? (float) $target->stop_loss : null;
            $targetPrice = $target?->target_price !== null ? (float) $target->target_price : null;

            // Which side of the fence the position currently sits on, given
            // the levels the investor set — surfaced so it's obvious at a
            // glance when a holding needs a decision, not just a data point.
            $positionStatus = match (true) {
                $currentPrice === null || ($stopLoss === null && $targetPrice === null) => null,
                $stopLoss !== null && $currentPrice <= $stopLoss => 'stop_breached',
                $targetPrice !== null && $currentPrice >= $targetPrice => 'target_reached',
                default => 'holding',
            };

            $holdings->push([
                'stock_id' => $stockId,
                'symbol' => $stock->symbol,
                'company_name' => $stock->company_name,
                'sector' => $stock->sector,
                'quantity' => $state['qty'],
                'avg_cost' => round($avgCost, 4),
                'invested' => round($state['total_cost'], 4),
                'current_price' => $currentPrice,
                'current_value' => $currentValue !== null ? round($currentValue, 4) : null,
                'unrealized_pnl' => $unrealizedPnl !== null ? round($unrealizedPnl, 4) : null,
                'unrealized_pnl_pct' => ($unrealizedPnl !== null && $state['total_cost'] > 0)
                    ? round(($unrealizedPnl / $state['total_cost']) * 100, 2)
                    : null,
                'latest_signal' => $stock->latestSignal,
                'stop_loss' => $stopLoss,
                'target_price' => $targetPrice,
                'target_notes' => $target?->notes,
                'position_status' => $positionStatus,
                'pct_to_stop' => ($currentPrice !== null && $stopLoss !== null && $currentPrice > 0)
                    ? round((($currentPrice - $stopLoss) / $currentPrice) * 100, 2) : null,
                'pct_to_target' => ($currentPrice !== null && $targetPrice !== null && $currentPrice > 0)
                    ? round((($targetPrice - $currentPrice) / $currentPrice) * 100, 2) : null,
                'bonus_shares_received' => $state['bonus_shares_received'],
            ]);
        }

        return $holdings->sortBy('symbol')->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function realizedPnl(Portfolio $portfolio): Collection
    {
        $replay = $this->replay($portfolio);

        if ($replay === []) {
            return collect();
        }

        $stocks = Stock::whereIn('id', array_keys($replay))->get()->keyBy('id');

        $lines = collect();

        foreach ($replay as $stockId => $state) {
            $stock = $stocks->get($stockId);

            foreach ($state['realized_lines'] as $line) {
                $tx = $line['transaction'];

                $lines->push([
                    'stock_id' => $stockId,
                    'symbol' => $stock?->symbol,
                    'transaction_id' => $tx->id,
                    'transaction_date' => $tx->transaction_date->toDateString(),
                    'quantity' => $tx->quantity,
                    'sell_price' => (float) $tx->price,
                    'avg_cost_at_time' => $line['avg_cost_at_time'],
                    'realized_pnl' => $line['realized_pnl'],
                ]);
            }
        }

        return $lines->sortByDesc('transaction_date')->values();
    }

    public function summary(Portfolio $portfolio): array
    {
        $holdings = $this->holdings($portfolio);
        $realized = $this->realizedPnl($portfolio);

        $totalInvested = (float) $holdings->sum('invested');
        $currentValue = (float) $holdings->sum('current_value');
        $unrealizedPnl = (float) $holdings->sum('unrealized_pnl');
        $realizedPnl = (float) $realized->sum('realized_pnl');

        return [
            'total_invested' => round($totalInvested, 4),
            'current_value' => round($currentValue, 4),
            'unrealized_pnl' => round($unrealizedPnl, 4),
            'unrealized_pnl_pct' => $totalInvested > 0 ? round(($unrealizedPnl / $totalInvested) * 100, 2) : null,
            'realized_pnl' => round($realizedPnl, 4),
            'total_pnl' => round($unrealizedPnl + $realizedPnl, 4),
            'holdings_count' => $holdings->count(),
        ];
    }

    /**
     * Portfolio value over time — for each stock ever held, walks its price
     * history day by day alongside that stock's own transaction timeline
     * (same weighted-average replay as everywhere else in this service),
     * summing each day's (quantity held that day) * (close that day) across
     * every stock. Entirely derived from the existing ledger + daily_prices;
     * nothing is stored. A day where a held stock has no price row simply
     * doesn't contribute that stock's value for that day (thinly-traded
     * stocks can have gaps) — a known, acceptable approximation.
     *
     * @return list<array{date: string, value: float, invested: float}>
     */
    public function valueHistory(Portfolio $portfolio): array
    {
        $transactionsByStock = $portfolio->transactions()
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get()
            ->groupBy('stock_id');

        if ($transactionsByStock->isEmpty()) {
            return [];
        }

        $valueByDate = [];
        $investedByDate = [];

        foreach ($transactionsByStock as $stockId => $transactions) {
            $transactions = $transactions->values();
            $firstDate = $transactions->first()->transaction_date->toDateString();

            $prices = DailyPrice::where('stock_id', $stockId)
                ->where('trade_date', '>=', $firstDate)
                ->orderBy('trade_date')
                ->get(['trade_date', 'close_price']);

            $txIndex = 0;
            $qty = 0;
            $totalCost = 0.0;

            foreach ($prices as $price) {
                $priceDate = $price->trade_date->toDateString();

                while ($txIndex < $transactions->count() && $transactions[$txIndex]->transaction_date->toDateString() <= $priceDate) {
                    $tx = $transactions[$txIndex];
                    $txQty = (int) $tx->quantity;

                    if ($tx->type === 'buy') {
                        $totalCost += ($txQty * (float) $tx->price) + (float) $tx->fees;
                        $qty += $txQty;
                    } else {
                        $avgCost = $qty > 0 ? $totalCost / $qty : 0.0;
                        $sellQty = min($txQty, $qty);
                        $totalCost -= $avgCost * $sellQty;
                        $qty -= $sellQty;
                    }

                    $txIndex++;
                }

                if ($qty > 0) {
                    $valueByDate[$priceDate] = ($valueByDate[$priceDate] ?? 0.0) + ($qty * (float) $price->close_price);
                    $investedByDate[$priceDate] = ($investedByDate[$priceDate] ?? 0.0) + $totalCost;
                }
            }
        }

        ksort($valueByDate);

        $history = [];
        foreach ($valueByDate as $date => $value) {
            $history[] = [
                'date' => $date,
                'value' => round($value, 4),
                'invested' => round($investedByDate[$date] ?? 0.0, 4),
            ];
        }

        return $history;
    }

    /**
     * Summary stats built on top of valueHistory() + summary() — best/worst
     * single-day move, the portfolio's all-time-high value, and XIRR (the
     * money-weighted annualized return, accounting for exactly when each
     * rupee went in or came out — the fair way to compare returns when
     * contributions weren't a single lump sum on day one).
     *
     * @param  list<array{date: string, value: float, invested: float}>  $history
     */
    public function performanceMetrics(Portfolio $portfolio, array $history): array
    {
        $summary = $this->summary($portfolio);

        $bestDay = null;
        $worstDay = null;

        // Day-over-day change in (value - invested), i.e. unrealized P&L —
        // using P&L rather than raw value means a deposit (buy) on a given
        // day doesn't itself look like a "gain," only actual price moves do.
        for ($i = 1; $i < count($history); $i++) {
            $todayPnl = $history[$i]['value'] - $history[$i]['invested'];
            $prevPnl = $history[$i - 1]['value'] - $history[$i - 1]['invested'];
            $delta = $todayPnl - $prevPnl;

            $row = ['date' => $history[$i]['date'], 'change' => round($delta, 4)];

            if ($bestDay === null || $delta > $bestDay['change']) {
                $bestDay = $row;
            }
            if ($worstDay === null || $delta < $worstDay['change']) {
                $worstDay = $row;
            }
        }

        $allTimeHigh = null;
        foreach ($history as $row) {
            if ($allTimeHigh === null || $row['value'] > $allTimeHigh['value']) {
                $allTimeHigh = ['date' => $row['date'], 'value' => round($row['value'], 4)];
            }
        }

        return [
            'total_invested' => $summary['total_invested'],
            'current_value' => $summary['current_value'],
            'total_pnl' => $summary['total_pnl'],
            'total_pnl_pct' => $summary['total_invested'] > 0 ? round(($summary['total_pnl'] / $summary['total_invested']) * 100, 2) : null,
            'best_day' => $bestDay,
            'worst_day' => $worstDay,
            'all_time_high' => $allTimeHigh,
            'xirr_pct' => $this->xirr($portfolio, $summary['current_value']),
        ];
    }

    /**
     * Money-weighted annualized return: every buy is a cash outflow, every
     * sell an inflow, and the current portfolio value is treated as a final
     * inflow today — the rate that makes all those cash flows, discounted
     * back to the first transaction date, net to zero. Solved numerically
     * (Newton-Raphson) since there's no closed form. Returns null rather
     * than a wild number if the flows don't converge to a sane answer.
     */
    private function xirr(Portfolio $portfolio, float $currentValue): ?float
    {
        $transactions = $portfolio->transactions()->orderBy('transaction_date')->get();

        if ($transactions->isEmpty() || $currentValue <= 0) {
            return null;
        }

        $flows = $transactions->map(fn ($tx) => [
            'date' => $tx->transaction_date,
            'amount' => $tx->type === 'buy'
                ? -(((int) $tx->quantity * (float) $tx->price) + (float) $tx->fees)
                : (((int) $tx->quantity * (float) $tx->price) - (float) $tx->fees),
        ])->values()->all();

        $flows[] = ['date' => now(), 'amount' => $currentValue];

        $hasPositive = collect($flows)->contains(fn ($f) => $f['amount'] > 0);
        $hasNegative = collect($flows)->contains(fn ($f) => $f['amount'] < 0);
        if (! $hasPositive || ! $hasNegative) {
            return null; // XIRR is undefined without at least one inflow and one outflow
        }

        $firstDate = $flows[0]['date'];
        $years = array_map(fn ($f) => $firstDate->diffInDays($f['date']) / 365, $flows);
        $amounts = array_column($flows, 'amount');

        $npv = function (float $rate) use ($amounts, $years): float {
            $sum = 0.0;
            foreach ($amounts as $i => $amount) {
                $sum += $amount / (1 + $rate) ** $years[$i];
            }

            return $sum;
        };

        $rate = 0.1;
        for ($i = 0; $i < 100; $i++) {
            $npv0 = $npv($rate);
            $derivative = ($npv($rate + 0.0001) - $npv0) / 0.0001;

            if (abs($derivative) < 1e-10) {
                break;
            }

            $nextRate = $rate - ($npv0 / $derivative);

            if (! is_finite($nextRate) || $nextRate <= -0.999) {
                return null;
            }
            if (abs($nextRate - $rate) < 1e-7) {
                $rate = $nextRate;
                break;
            }

            $rate = $nextRate;
        }

        // A converged-but-absurd rate (e.g. from a pathological flow
        // pattern) is more useful reported as "unavailable" than as a
        // nonsensical number like +50,000%.
        if (! is_finite($rate) || abs($rate) > 10) {
            return null;
        }

        return round($rate * 100, 2);
    }

    /**
     * Throws if adding this transaction would ever make the running quantity
     * for this stock negative at any point in the chronological replay
     * (covers backdated entries too, not just the final total).
     */
    public function assertTransactionIsValid(
        Portfolio $portfolio,
        int $stockId,
        string $type,
        int $quantity,
        string $transactionDate,
        ?int $excludeTransactionId = null,
    ): void {
        if ($type !== 'sell') {
            return;
        }

        $existing = $portfolio->transactions()
            ->where('stock_id', $stockId)
            ->when($excludeTransactionId, fn ($q) => $q->where('id', '!=', $excludeTransactionId))
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $pending = (object) [
            'id' => PHP_INT_MAX,
            'type' => 'sell',
            'quantity' => $quantity,
            'price' => 0,
            'fees' => 0,
            'transaction_date' => \Illuminate\Support\Carbon::parse($transactionDate),
        ];

        $sequence = $existing->push($pending)->sortBy([
            fn ($a, $b) => $a->transaction_date <=> $b->transaction_date,
            fn ($a, $b) => $a->id <=> $b->id,
        ])->values();

        $qty = 0;
        foreach ($sequence as $tx) {
            $qty += $tx->type === 'buy' ? $tx->quantity : -$tx->quantity;

            if ($qty < 0) {
                throw new RuntimeException(
                    "This sell would leave a negative position — you'd be selling more shares than held as of {$tx->transaction_date->toDateString()}."
                );
            }
        }
    }

    /**
     * @return array<int, array{qty: int, total_cost: float, realized_pnl: float, realized_lines: array, bonus_shares_received: int}>
     */
    private function replay(Portfolio $portfolio): array
    {
        $transactionsByStock = $portfolio->transactions()
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get()
            ->groupBy('stock_id');

        $bonusEventsByStock = $this->bonusEvents(array_keys($transactionsByStock->all()));

        $result = [];

        foreach ($transactionsByStock as $stockId => $transactions) {
            // Buys/sells and bonus-share credits share one chronological
            // timeline per stock — a bonus that lands between two trades has
            // to inflate only the quantity actually held at that point, not
            // shares bought later. Same-day ties resolve transactions first
            // (a same-day buy is available to receive that day's bonus;
            // ambiguous either way, but this is the more common real case).
            $events = $transactions->map(fn ($tx) => ['date' => $tx->transaction_date->toDateString(), 'kind' => $tx->type, 'tx' => $tx])
                ->concat(($bonusEventsByStock[$stockId] ?? [])->map(fn ($ev) => ['date' => $ev['date'], 'kind' => 'bonus', 'bonus' => $ev]))
                ->sortBy([['date', 'asc'], ['kind', 'asc']])
                ->values();

            $qty = 0;
            $totalCost = 0.0;
            $realizedPnl = 0.0;
            $realizedLines = [];
            $bonusSharesReceived = 0;

            foreach ($events as $event) {
                if ($event['kind'] === 'bonus') {
                    if ($qty > 0) {
                        $credited = (int) floor($qty * $event['bonus']['pct'] / 100);
                        $qty += $credited;
                        $bonusSharesReceived += $credited;
                    }

                    continue;
                }

                $tx = $event['tx'];
                $txQty = (int) $tx->quantity;
                $txPrice = (float) $tx->price;
                $txFees = (float) $tx->fees;

                if ($tx->type === 'buy') {
                    $totalCost += ($txQty * $txPrice) + $txFees;
                    $qty += $txQty;

                    continue;
                }

                $avgCost = $qty > 0 ? $totalCost / $qty : 0.0;
                $sellQty = min($txQty, $qty);
                $costOfSold = $avgCost * $sellQty;
                $proceeds = ($sellQty * $txPrice) - $txFees;
                $realized = $proceeds - $costOfSold;

                $realizedPnl += $realized;
                $realizedLines[] = [
                    'transaction' => $tx,
                    'avg_cost_at_time' => round($avgCost, 4),
                    'realized_pnl' => round($realized, 4),
                ];

                $qty -= $sellQty;
                $totalCost -= $costOfSold;
            }

            $result[$stockId] = [
                'qty' => $qty,
                'total_cost' => $totalCost,
                'realized_pnl' => $realizedPnl,
                'realized_lines' => $realizedLines,
                'bonus_shares_received' => $bonusSharesReceived,
            ];
        }

        return $result;
    }

    /**
     * Bonus-share declarations for the given stocks, keyed by stock_id, each
     * as a {date, pct} event. Dated by announcement_date since that's the
     * only date NEPSE's own dividend feed actually provides — the real
     * credit date is typically some weeks later (after book closure), so
     * this is a documented best-effort approximation, not exact. Skips any
     * declaration missing that date entirely rather than guessing further.
     *
     * @param  list<int>  $stockIds
     * @return array<int, Collection<int, array{date: string, pct: float}>>
     */
    private function bonusEvents(array $stockIds): array
    {
        if ($stockIds === []) {
            return [];
        }

        return Dividend::whereIn('stock_id', $stockIds)
            ->where('bonus_share_pct', '>', 0)
            ->whereNotNull('announcement_date')
            ->get()
            ->groupBy('stock_id')
            ->map(fn ($rows) => $rows->map(fn ($d) => [
                'date' => $d->announcement_date->toDateString(),
                'pct' => (float) $d->bonus_share_pct,
            ])->values())
            ->all();
    }
}
