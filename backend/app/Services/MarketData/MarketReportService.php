<?php

namespace App\Services\MarketData;

use App\Models\ScrapeLog;
use App\Models\Stock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only aggregate queries backing the dashboard's reporting views.
 * Nothing here mutates data — it's all derived from stocks/daily_prices/signals.
 */
class MarketReportService
{
    private const DIVIDEND_FACE_VALUE = 100.0;

    /**
     * Latest close, previous close, and % change per stock — the one piece of
     * real aggregate SQL here, everything else reuses existing relations.
     *
     * @return Collection<int, array{stock_id: int, close: float, previous_close: ?float, change_pct: ?float}>
     */
    public function priceChanges(): Collection
    {
        $ranked = DB::table('daily_prices')
            ->selectRaw('stock_id, trade_date, close_price, turnover, ROW_NUMBER() OVER (PARTITION BY stock_id ORDER BY trade_date DESC) as rn');

        $rows = DB::query()
            ->fromSub($ranked, 'ranked')
            ->where('rn', '<=', 2)
            ->orderBy('stock_id')
            ->orderBy('rn')
            ->get();

        return $rows->groupBy('stock_id')->map(function ($group) {
            $latest = $group->firstWhere('rn', 1);
            $previous = $group->firstWhere('rn', 2);

            $close = (float) $latest->close_price;
            $previousClose = $previous ? (float) $previous->close_price : null;
            $changePct = $previousClose && $previousClose != 0.0
                ? round((($close - $previousClose) / $previousClose) * 100, 2)
                : null;

            return [
                'stock_id' => $latest->stock_id,
                'close' => $close,
                'previous_close' => $previousClose,
                'change_pct' => $changePct,
                'turnover' => (float) $latest->turnover,
            ];
        });
    }

    /**
     * Per-sector rollup for today's move — stock count, breadth, average
     * change %, and total turnover. Built on priceChanges() rather than a
     * fresh query, same aggregate-then-map style as the rest of this class.
     *
     * @return Collection<int, array{sector: string, stock_count: int, advancing: int, declining: int, avg_change_pct: ?float, total_turnover: float}>
     */
    public function sectorPerformance(): Collection
    {
        $changes = $this->priceChanges();
        $stocks = Stock::all(['id', 'sector']);

        return $stocks->groupBy(fn ($s) => $s->sector ?: 'Other')
            ->map(function ($group, $sector) use ($changes) {
                $sectorChanges = $group->map(fn ($s) => $changes->get($s->id))->filter()->values();
                $withPct = $sectorChanges->filter(fn ($c) => $c['change_pct'] !== null);

                return [
                    'sector' => $sector,
                    'stock_count' => $group->count(),
                    'advancing' => $withPct->where('change_pct', '>', 0)->count(),
                    'declining' => $withPct->where('change_pct', '<', 0)->count(),
                    'avg_change_pct' => $withPct->isNotEmpty() ? round($withPct->avg('change_pct'), 2) : null,
                    'total_turnover' => round($sectorChanges->sum('turnover'), 2),
                ];
            })
            ->sortByDesc('total_turnover')
            ->values();
    }

    /**
     * Daily market breadth + turnover over a trailing window — the trend
     * line behind both the market report and (via $sector) a sector report.
     * Computed with a LAG window function so each day's advance/decline
     * count is a same-stock day-over-day comparison, not just a snapshot.
     *
     * @return Collection<int, array{trade_date: string, advancing: int, declining: int, total_turnover: float}>
     */
    public function trend(int $days = 30, ?string $sector = null): Collection
    {
        $since = now()->subDays($days)->toDateString();
        // A buffer before $since so LAG has a real prior close to compare
        // against for the very first day inside the reported window.
        $bufferSince = now()->subDays($days + 10)->toDateString();

        $stockIds = $sector !== null ? Stock::where('sector', $sector)->pluck('id') : null;

        $withPrev = DB::table('daily_prices')
            ->where('trade_date', '>=', $bufferSince)
            ->when($stockIds !== null, fn ($q) => $q->whereIn('stock_id', $stockIds))
            ->selectRaw('stock_id, trade_date, close_price, turnover, LAG(close_price) OVER (PARTITION BY stock_id ORDER BY trade_date) as prev_close');

        $rows = DB::query()
            ->fromSub($withPrev, 'w')
            ->where('trade_date', '>=', $since)
            ->whereNotNull('prev_close')
            ->groupBy('trade_date')
            ->selectRaw('trade_date,
                SUM(CASE WHEN close_price > prev_close THEN 1 ELSE 0 END) as advancing,
                SUM(CASE WHEN close_price < prev_close THEN 1 ELSE 0 END) as declining,
                SUM(turnover) as total_turnover')
            ->orderBy('trade_date')
            ->get();

        return $rows->map(fn ($r) => [
            'trade_date' => $r->trade_date,
            'advancing' => (int) $r->advancing,
            'declining' => (int) $r->declining,
            'total_turnover' => round((float) $r->total_turnover, 2),
        ]);
    }

    /**
     * % change from the closest available close on/before each standard
     * lookback point to the stock's latest close. A handful of point
     * queries rather than one big scan — this is a single-stock report,
     * not a bulk operation.
     *
     * @return array<string, ?float>
     */
    public function stockReturns(Stock $stock): array
    {
        $latest = $stock->dailyPrices()->orderByDesc('trade_date')->first();

        if (! $latest) {
            return [];
        }

        $currentClose = (float) $latest->close_price;

        $periods = [
            '1w' => now()->subDays(7),
            '1m' => now()->subDays(30),
            '3m' => now()->subDays(91),
            '6m' => now()->subDays(182),
            '1y' => now()->subDays(365),
            'ytd' => now()->startOfYear(),
        ];

        $returns = [];

        foreach ($periods as $key => $date) {
            $past = $stock->dailyPrices()
                ->where('trade_date', '<=', $date->toDateString())
                ->orderByDesc('trade_date')
                ->first();

            $pastClose = $past ? (float) $past->close_price : null;

            $returns[$key] = $pastClose && $pastClose != 0.0
                ? round((($currentClose - $pastClose) / $pastClose) * 100, 2)
                : null;
        }

        return $returns;
    }

    /**
     * 52-week high/low per stock, and how far the current close sits from
     * each — same aggregate-then-map style as priceChanges().
     *
     * @return Collection<int, array{stock_id: int, high_52w: float, low_52w: float, current_price: float, pct_from_high: float, pct_from_low: ?float}>
     */
    public function fiftyTwoWeekRange(): Collection
    {
        $since = now()->subDays(365)->toDateString();

        $ranges = DB::table('daily_prices')
            ->where('trade_date', '>=', $since)
            ->groupBy('stock_id')
            ->selectRaw('stock_id, MAX(high_price) as high_52w, MIN(low_price) as low_52w')
            ->get()
            ->keyBy('stock_id');

        $currentCloses = $this->priceChanges();

        return $ranges->map(function ($range) use ($currentCloses) {
            $current = $currentCloses->get($range->stock_id);
            $currentPrice = $current ? $current['close'] : null;
            $high = (float) $range->high_52w;
            $low = (float) $range->low_52w;

            return [
                'stock_id' => $range->stock_id,
                'high_52w' => $high,
                'low_52w' => $low,
                'current_price' => $currentPrice,
                'pct_from_high' => $currentPrice !== null && $high > 0 ? round((($currentPrice - $high) / $high) * 100, 2) : null,
                'pct_from_low' => $currentPrice !== null && $low > 0 ? round((($currentPrice - $low) / $low) * 100, 2) : null,
            ];
        });
    }

    public function dashboardSummary(): array
    {
        $changes = $this->priceChanges();

        $stocks = Stock::with('latestSignal')->get();

        $signalCounts = [
            'strong_buy' => 0, 'buy' => 0, 'hold' => 0, 'sell' => 0, 'strong_sell' => 0,
        ];
        $stocksWithSignals = 0;

        foreach ($stocks as $stock) {
            if ($stock->latestSignal) {
                $signalCounts[$stock->latestSignal->signal]++;
                $stocksWithSignals++;
            }
        }

        $advancing = $changes->where('change_pct', '>', 0)->count();
        $declining = $changes->where('change_pct', '<', 0)->count();
        $unchanged = $changes->filter(fn ($c) => $c['change_pct'] === 0.0)->count();

        $withSymbols = $changes->map(function ($c) use ($stocks) {
            $stock = $stocks->firstWhere('id', $c['stock_id']);

            return $stock ? array_merge($c, [
                'symbol' => $stock->symbol,
                'company_name' => $stock->company_name,
            ]) : null;
        })->filter()->values();

        $ranked = $withSymbols->filter(fn ($c) => $c['change_pct'] !== null)->sortByDesc('change_pct')->values();

        return [
            'totals' => [
                'stocks' => $stocks->count(),
                'with_signals' => $stocksWithSignals,
            ],
            'last_scrape' => ScrapeLog::latest('created_at')->first(),
            'signal_counts' => $signalCounts,
            'breadth' => [
                'advancing' => $advancing,
                'declining' => $declining,
                'unchanged' => $unchanged,
            ],
            // Today's real per-sector move (avg % change, advancing/declining
            // counts) — same data as the Market Report's sector table, not a
            // stock-count tally, so this actually changes day to day instead
            // of sitting nearly static until a stock gets added/removed.
            'sector_breakdown' => $this->sectorPerformance(),
            'movers' => [
                'gainers' => $ranked->take(5)->values(),
                'losers' => $ranked->reverse()->take(5)->values(),
            ],
        ];
    }

    /**
     * Market-wide dividend ranking — one row per stock that has at least one
     * recorded dividend, ranked by trailing dividend yield. Cash dividend %
     * is declared against face value, not market price — most NEPSE equities
     * are Rs. 100 face value (the fallback here), but some instruments (e.g.
     * mutual fund units) use a different one, captured per-stock via
     * NepalStockCorporateActionsService when available.
     *
     * @return array{totals: array, stocks: Collection}
     */
    public function dividendReport(?string $sector = null): array
    {
        $query = Stock::query()->with([
            'latestPrice', 'latestSignal',
            'dividends' => fn ($q) => $q->orderByDesc('fiscal_year'),
            'rightShares' => fn ($q) => $q->orderByDesc('opening_date'),
        ]);

        if ($sector) {
            $query->where('sector', $sector);
        }

        $rows = $query->get()
            ->filter(fn ($stock) => $stock->dividends->isNotEmpty())
            ->map(fn ($stock) => $this->summarizeDividends($stock))
            ->sortByDesc(fn ($r) => $r['dividend_yield_pct'] ?? -1)
            ->values();

        $yields = $rows->pluck('dividend_yield_pct')->filter(fn ($v) => $v !== null);

        return [
            'totals' => [
                'stocks_with_dividends' => $rows->count(),
                'avg_yield_pct' => $yields->isNotEmpty() ? round($yields->avg(), 2) : null,
                'top_yield_pct' => $yields->isNotEmpty() ? $yields->max() : null,
                'stocks_with_right_shares' => $rows->filter(fn ($r) => $r['right_share_count'] > 0)->count(),
            ],
            'top_picks' => $this->rankDividendPicks($rows),
            'stocks' => $rows,
        ];
    }

    /**
     * Single-stock dividend summary — same shape/math as one row of
     * dividendReport(), reused there and by the Analyst Report so the yield
     * calculation can't drift between the two. Returns null if the stock has
     * no recorded dividend history.
     */
    public function stockDividendSummary(Stock $stock): ?array
    {
        $stock->loadMissing([
            'latestPrice', 'latestSignal',
            'dividends' => fn ($q) => $q->orderByDesc('fiscal_year'),
            'rightShares' => fn ($q) => $q->orderByDesc('opening_date'),
        ]);

        if ($stock->dividends->isEmpty()) {
            return null;
        }

        return $this->summarizeDividends($stock);
    }

    private function summarizeDividends(Stock $stock): array
    {
        $latest = $stock->dividends->first();
        $close = $stock->latestPrice?->close_price;
        $faceValue = $stock->face_value !== null ? (float) $stock->face_value : self::DIVIDEND_FACE_VALUE;
        $latestCashRs = $latest->cash_dividend_pct !== null
            ? (float) $latest->cash_dividend_pct * $faceValue / 100
            : null;

        $totals = $stock->dividends->pluck('total_dividend_pct')->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v);

        $cashYieldPct = ($latestCashRs !== null && $close > 0) ? ($latestCashRs / $close) * 100 : null;
        $bonusPct = $latest->bonus_share_pct !== null ? (float) $latest->bonus_share_pct : null;

        // The declared Cash/Bonus/Total % columns are all against Rs. 100 face
        // value, not what the stock actually trades at — misleading once price
        // has moved far from face value (e.g. Rs. 539 vs Rs. 100 for NABIL).
        // Bonus shares don't need that face-value conversion: a 6% bonus means
        // 6% more shares, and those extra shares are worth the same current
        // price as the ones already held — so bonus_share_pct is already a
        // real, price-relative % on its own. Only the cash portion needs
        // converting (via dividend_yield_pct above). Total actual return here
        // is that converted cash yield plus the (already price-relative) bonus %.
        $actualTotalYieldPct = ($cashYieldPct !== null || $bonusPct !== null)
            ? round(($cashYieldPct ?? 0) + ($bonusPct ?? 0), 2)
            : null;

        $latestRightShare = $stock->rightShares->first();

        return [
            'stock_id' => $stock->id,
            'symbol' => $stock->symbol,
            'company_name' => $stock->company_name,
            'sector' => $stock->sector,
            'close' => $close,
            'latest_fiscal_year' => $latest->fiscal_year,
            'latest_cash_pct' => $latest->cash_dividend_pct !== null ? (float) $latest->cash_dividend_pct : null,
            'latest_bonus_pct' => $bonusPct,
            'latest_total_pct' => $latest->total_dividend_pct !== null ? (float) $latest->total_dividend_pct : null,
            'dividend_yield_pct' => $cashYieldPct !== null ? round($cashYieldPct, 2) : null,
            'actual_total_yield_pct' => $actualTotalYieldPct,
            'years_recorded' => $stock->dividends->count(),
            'avg_total_dividend_pct' => $totals->isNotEmpty() ? round($totals->avg(), 2) : null,
            'latest_signal' => $stock->latestSignal?->signal,
            'history' => $stock->dividends->values(),
            'right_share_count' => $stock->rightShares->count(),
            'latest_right_share_ratio' => $latestRightShare?->ratio,
            'latest_right_share_pct' => $latestRightShare?->percent(),
            'latest_right_share_year' => $latestRightShare?->opening_date?->format('Y') ?? $latestRightShare?->listing_date?->format('Y'),
            'right_share_history' => $stock->rightShares->map(fn ($rs) => [
                'id' => $rs->id,
                'year' => $rs->opening_date?->format('Y') ?? $rs->listing_date?->format('Y'),
                'ratio' => $rs->ratio,
                'pct' => $rs->percent(),
                'issue_price' => $rs->issue_price !== null ? (float) $rs->issue_price : null,
                'opening_date' => $rs->opening_date,
                'closing_date' => $rs->closing_date,
                'listing_date' => $rs->listing_date,
                'status' => $rs->status,
            ])->values(),
        ];
    }

    /**
     * Ranks dividend-paying stocks by a transparent weighted score — trailing
     * yield (50%), payout consistency across recorded years (30%), and
     * historical average total payout (20%) — each normalized against the
     * best value among candidates so no single metric dominates just because
     * of its raw scale. Requires an actual cash yield (bonus-only years don't
     * count as an income pick) and a latest signal that isn't bearish, so
     * this can't recommend a stock currently flagged Sell/Strong Sell.
     *
     * @param  Collection  $rows
     * @return Collection
     */
    private function rankDividendPicks(Collection $rows): Collection
    {
        $candidates = $rows->filter(fn ($r) => ($r['dividend_yield_pct'] ?? 0) > 0
            && ! in_array($r['latest_signal'], ['sell', 'strong_sell'], true));

        if ($candidates->isEmpty()) {
            return collect();
        }

        $maxYield = $candidates->max('dividend_yield_pct');
        $maxYears = $candidates->max('years_recorded');
        $maxAvg = $candidates->max(fn ($r) => $r['avg_total_dividend_pct'] ?? 0) ?: 1;

        return $candidates->map(function ($r) use ($maxYield, $maxYears, $maxAvg) {
            $yieldScore = $maxYield > 0 ? $r['dividend_yield_pct'] / $maxYield : 0;
            $consistencyScore = $maxYears > 0 ? $r['years_recorded'] / $maxYears : 0;
            $growthScore = ($r['avg_total_dividend_pct'] ?? 0) / $maxAvg;

            $score = round((0.5 * $yieldScore + 0.3 * $consistencyScore + 0.2 * $growthScore) * 100, 1);

            $reasons = [];
            $reasons[] = "{$r['dividend_yield_pct']}% trailing yield";
            $reasons[] = "{$r['years_recorded']} year(s) of recorded payouts";
            if ($r['avg_total_dividend_pct'] !== null) {
                $reasons[] = "averages {$r['avg_total_dividend_pct']}% total dividend historically";
            }
            if ($r['latest_signal']) {
                $reasons[] = 'currently flagged '.str_replace('_', ' ', $r['latest_signal']);
            }

            return [...$r, 'pick_score' => $score, 'reasons' => $reasons];
        })
            ->sortByDesc('pick_score')
            ->take(10)
            ->values();
    }
}
