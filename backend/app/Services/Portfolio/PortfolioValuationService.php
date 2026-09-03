<?php

namespace App\Services\Portfolio;

use App\Models\DailyPrice;
use App\Models\Portfolio;
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

            $holdings->push([
                'stock_id' => $stockId,
                'symbol' => $stock->symbol,
                'company_name' => $stock->company_name,
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
     * @return array<int, array{qty: int, total_cost: float, realized_pnl: float, realized_lines: array}>
     */
    private function replay(Portfolio $portfolio): array
    {
        $transactionsByStock = $portfolio->transactions()
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get()
            ->groupBy('stock_id');

        $result = [];

        foreach ($transactionsByStock as $stockId => $transactions) {
            $qty = 0;
            $totalCost = 0.0;
            $realizedPnl = 0.0;
            $realizedLines = [];

            foreach ($transactions as $tx) {
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
            ];
        }

        return $result;
    }
}
