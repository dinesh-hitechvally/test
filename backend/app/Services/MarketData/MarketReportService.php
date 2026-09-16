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

    /**
     * Per-stock data for the "Long-Term Investment" screen — dividend
     * consistency, right-share dilution history, 3-year price return, and
     * volatility/liquidity over the trailing year. Deliberately NOT company
     * fundamentals (EPS, P/E, book value, ROE) — this app doesn't have that
     * data at all, so this is a quality/consistency read, not a valuation
     * one. Built from bulk SQL aggregates (not per-stock queries) since this
     * runs across the whole market at once.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function longTermCandidates(): Collection
    {
        $stocks = Stock::with('latestSignal')->get();
        $closes = $this->priceChanges();

        $divStats = DB::table('dividends')
            ->selectRaw('stock_id, COUNT(*) as years_recorded, AVG(total_dividend_pct) as avg_total_dividend_pct')
            ->groupBy('stock_id')
            ->get()
            ->keyBy('stock_id');

        $rightShareCounts = DB::table('right_shares')
            ->selectRaw('stock_id, COUNT(*) as right_share_count')
            ->groupBy('stock_id')
            ->get()
            ->keyBy('stock_id');

        // Closest available price on/before 3 years ago, per stock — most
        // stocks won't have one yet (NEPSE's own API only reaches back ~1yr;
        // full history requires a one-time "Fetch Full History" per stock),
        // handled as an honest "not enough history yet", not a penalty.
        $cutoff3y = now()->subYears(3)->toDateString();
        $ranked3y = DB::table('daily_prices')
            ->where('trade_date', '<=', $cutoff3y)
            ->selectRaw('stock_id, close_price, ROW_NUMBER() OVER (PARTITION BY stock_id ORDER BY trade_date DESC) as rn');
        $threeYearAgo = DB::query()->fromSub($ranked3y, 'r')->where('rn', 1)->get()->keyBy('stock_id');

        // Volatility (stdev of daily % returns) and liquidity (avg turnover)
        // over the trailing year — day-over-day change via LAG, then
        // aggregated per stock in one pass.
        $since = now()->subDays(365)->toDateString();
        $withPrev = DB::table('daily_prices')
            ->where('trade_date', '>=', $since)
            ->selectRaw('stock_id, turnover, close_price, LAG(close_price) OVER (PARTITION BY stock_id ORDER BY trade_date) as prev_close');
        $dailyReturns = DB::query()->fromSub($withPrev, 'w')
            ->whereNotNull('prev_close')
            ->where('prev_close', '>', 0)
            ->selectRaw('stock_id, ((close_price - prev_close) / prev_close) * 100 as daily_return, turnover');
        $volLiquidity = DB::query()->fromSub($dailyReturns, 'r')
            ->groupBy('stock_id')
            ->selectRaw('stock_id, STDDEV_POP(daily_return) as volatility_pct, AVG(turnover) as avg_turnover, COUNT(*) as return_days')
            ->get()
            ->keyBy('stock_id');

        // A stdev computed from only a handful of day-over-day returns isn't
        // a real volatility read (2 price rows -> 1 return -> stdev is always
        // exactly 0, which would look like the *most* stable stock in the
        // market) — require a real sample before trusting it.
        $minReturnDays = 60;

        return $stocks->map(function ($stock) use ($closes, $divStats, $rightShareCounts, $threeYearAgo, $volLiquidity, $minReturnDays) {
            $close = $closes->get($stock->id)['close'] ?? null;
            $div = $divStats->get($stock->id);
            $rightShares = $rightShareCounts->get($stock->id);
            $past = $threeYearAgo->get($stock->id);
            $vol = $volLiquidity->get($stock->id);
            $hasEnoughReturns = $vol && (int) $vol->return_days >= $minReturnDays;

            $return3y = ($close !== null && $past && (float) $past->close_price > 0)
                ? round((($close - (float) $past->close_price) / (float) $past->close_price) * 100, 2)
                : null;

            return [
                'stock_id' => $stock->id,
                'symbol' => $stock->symbol,
                'company_name' => $stock->company_name,
                'sector' => $stock->sector,
                'share_group' => $stock->share_group,
                'close' => $close,
                'dividend_years_recorded' => $div->years_recorded ?? 0,
                'avg_total_dividend_pct' => $div && $div->avg_total_dividend_pct !== null ? round((float) $div->avg_total_dividend_pct, 2) : null,
                'right_share_count' => $rightShares->right_share_count ?? 0,
                'return_3y_pct' => $return3y,
                'volatility_pct' => $hasEnoughReturns && $vol->volatility_pct !== null ? round((float) $vol->volatility_pct, 2) : null,
                'avg_turnover' => $vol && $vol->avg_turnover !== null ? round((float) $vol->avg_turnover, 2) : null,
                'latest_signal' => $stock->latestSignal?->signal,
            ];
        })->values();
    }

    /**
     * Ranks stocks for long-term holding — a transparent weighted score:
     * dividend consistency (30%), dividend yield (15%), dilution discipline
     * i.e. fewer right-share issues (20%), low volatility (20%), and 3-year
     * return (15%, neutral-scored rather than penalized when not enough
     * history exists yet). Gated on: not currently a Sell/Strong Sell, and
     * real trailing liquidity (an illiquid stock can't be exited later no
     * matter how good the story). This is a quality/consistency read using
     * only data this app actually has — not a substitute for real
     * fundamental analysis (no EPS/P/E/book value/ROE tracked).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rankLongTermCandidates(?string $sector = null): Collection
    {
        $rows = $this->longTermCandidates();

        if ($sector) {
            $rows = $rows->where('sector', $sector);
        }

        // Liquidity and a non-bearish signal are real, hard requirements —
        // an illiquid stock can't be exited later, and this shouldn't
        // recommend a stock currently flagged Sell/Strong Sell no matter how
        // good its history looks. Volatility/3-year-return data being
        // missing is NOT gated on here — see the neutral-scoring note below.
        $candidates = $rows->filter(fn ($r) => ! in_array($r['latest_signal'], ['sell', 'strong_sell'], true)
            && $r['avg_turnover'] !== null && $r['avg_turnover'] > 0);

        if ($candidates->isEmpty()) {
            return collect();
        }

        $maxDivYears = max($candidates->max('dividend_years_recorded'), 1);
        $maxYield = $candidates->max('avg_total_dividend_pct') ?: 1;
        $maxDilution = max($candidates->max('right_share_count'), 1);
        $vols = $candidates->pluck('volatility_pct')->filter(fn ($v) => $v !== null);
        $minVol = $vols->isNotEmpty() ? $vols->min() : null;
        $volRange = $minVol !== null ? max($vols->max() - $minVol, 0.0001) : null;
        $returns = $candidates->pluck('return_3y_pct')->filter(fn ($v) => $v !== null);
        $maxReturn = $returns->isNotEmpty() ? $returns->max() : null;
        $minReturn = $returns->isNotEmpty() ? $returns->min() : null;
        $returnRange = $maxReturn !== null ? max($maxReturn - $minReturn, 0.0001) : null;

        return $candidates->map(function ($r) use ($maxDivYears, $maxYield, $maxDilution, $minVol, $volRange, $minReturn, $returnRange) {
            $divScore = $r['dividend_years_recorded'] / $maxDivYears;
            $yieldScore = $maxYield > 0 ? ($r['avg_total_dividend_pct'] ?? 0) / $maxYield : 0;
            $dilutionScore = 1 - ($r['right_share_count'] / $maxDilution);
            // Not enough trailing price history for a real volatility or
            // 3-year-return read is common here (NEPSE-only sourcing caps
            // most stocks at ~1yr until someone fetches full history) —
            // scored neutral (0.5) rather than penalized either way, since
            // it reflects a data gap, not a real quality signal.
            $volScore = ($r['volatility_pct'] !== null && $volRange !== null)
                ? 1 - (($r['volatility_pct'] - $minVol) / $volRange)
                : 0.5;
            $returnScore = ($r['return_3y_pct'] !== null && $returnRange !== null)
                ? ($r['return_3y_pct'] - $minReturn) / $returnRange
                : 0.5;

            $score = round((0.30 * $divScore + 0.15 * $yieldScore + 0.20 * $dilutionScore + 0.20 * $volScore + 0.15 * $returnScore) * 100, 1);

            $reasons = [];
            $reasons[] = $r['dividend_years_recorded'] > 0
                ? "{$r['dividend_years_recorded']} year(s) of recorded dividends"
                : 'no recorded dividend history';
            $reasons[] = $r['right_share_count'] > 0
                ? "{$r['right_share_count']} right-share issue(s) on record"
                : 'no right-share dilution on record';
            $reasons[] = $r['volatility_pct'] !== null
                ? "volatility {$r['volatility_pct']}% (daily stdev, trailing year)"
                : 'not enough price history yet for a volatility read';
            $reasons[] = $r['return_3y_pct'] !== null
                ? "{$r['return_3y_pct']}% over 3 years"
                : 'not enough price history yet for a 3-year return';
            if ($r['share_group']) {
                $reasons[] = "NEPSE Group {$r['share_group']}";
            }

            return [...$r, 'long_term_score' => $score, 'reasons' => $reasons];
        })
            ->sortByDesc('long_term_score')
            ->values();
    }

    /**
     * Per-stock snapshot for the "Short-Term" screen — today's fast-moving
     * technical state (SMA20-vs-SMA50 relationship, RSI, MACD histogram,
     * Bollinger position). Distinct from the single blended Signal score,
     * which also weighs the SMA50/200 golden/death cross (a long-term
     * signal) into the same number.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function shortTermCandidates(): Collection
    {
        $stocks = Stock::with('latestIndicator')->get();
        $closes = $this->priceChanges();

        return $stocks->map(function ($stock) use ($closes) {
            $price = $closes->get($stock->id);
            $ind = $stock->latestIndicator;

            return [
                'stock_id' => $stock->id,
                'symbol' => $stock->symbol,
                'company_name' => $stock->company_name,
                'sector' => $stock->sector,
                'share_group' => $stock->share_group,
                'close' => $price['close'] ?? null,
                'change_pct' => $price['change_pct'] ?? null,
                'turnover' => $price['turnover'] ?? null,
                'sma_20' => $ind?->sma_20 !== null ? (float) $ind->sma_20 : null,
                'sma_50' => $ind?->sma_50 !== null ? (float) $ind->sma_50 : null,
                'rsi_14' => $ind?->rsi_14 !== null ? (float) $ind->rsi_14 : null,
                'macd_histogram' => $ind?->macd_histogram !== null ? (float) $ind->macd_histogram : null,
                'bb_upper' => $ind?->bb_upper !== null ? (float) $ind->bb_upper : null,
                'bb_lower' => $ind?->bb_lower !== null ? (float) $ind->bb_lower : null,
            ];
        })->values();
    }

    /**
     * Ranks stocks for short-term/swing entries — a signed score (-100 to
     * +100, positive = bullish lean) over today's fast-moving technical
     * state: SMA20-above-SMA50 momentum (30%), RSI positioned for upside
     * without being overbought (25%), MACD histogram strength (25%), and
     * proximity to the lower Bollinger Band as a rebound cue (20%). Targets
     * a multi-day-to-few-weeks hold. Gated only on real liquidity today and
     * having the indicators to score — unlike the long-term screen, this
     * deliberately does NOT exclude bearish stocks, since the point is to
     * also surface short-term sell/avoid candidates.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rankShortTermCandidates(?string $sector = null): Collection
    {
        $rows = $this->shortTermCandidates();

        if ($sector) {
            $rows = $rows->where('sector', $sector);
        }

        $candidates = $rows->filter(fn ($r) => $r['turnover'] !== null && $r['turnover'] > 0
            && $r['sma_20'] !== null && $r['sma_50'] !== null && $r['rsi_14'] !== null);

        if ($candidates->isEmpty()) {
            return collect();
        }

        $macdAbs = $candidates->pluck('macd_histogram')->filter(fn ($v) => $v !== null)->map(fn ($v) => abs($v));
        $maxAbsMacd = $macdAbs->isNotEmpty() ? max($macdAbs->max(), 0.0001) : null;

        return $candidates->map(function ($r) use ($maxAbsMacd) {
            // How far SMA20 sits above/below SMA50, as % of SMA50 — capped at
            // +/-10% so one extreme outlier doesn't flatten everyone else.
            $smaGapPct = $r['sma_50'] > 0 ? (($r['sma_20'] - $r['sma_50']) / $r['sma_50']) * 100 : 0;
            $smaSigned = max(-1, min(1, $smaGapPct / 10));

            // RSI: peaks positive at 50 (bullish momentum, not yet
            // overbought), turns negative past roughly 30/70.
            $rsiSigned = max(-1, min(1, (50 - $r['rsi_14']) / 20));

            $macdSigned = ($r['macd_histogram'] !== null && $maxAbsMacd !== null)
                ? max(-1, min(1, $r['macd_histogram'] / $maxAbsMacd))
                : 0;

            // Proximity to the lower Bollinger Band = bullish rebound cue;
            // proximity to the upper band = bearish pullback cue.
            $bbSigned = 0;
            if ($r['bb_upper'] !== null && $r['bb_lower'] !== null && $r['close'] !== null) {
                $range = max($r['bb_upper'] - $r['bb_lower'], 0.0001);
                $bbSigned = max(-1, min(1, 1 - 2 * (($r['close'] - $r['bb_lower']) / $range)));
            }

            $score = round((0.30 * $smaSigned + 0.25 * $rsiSigned + 0.25 * $macdSigned + 0.20 * $bbSigned) * 100, 1);

            $reasons = [];
            $reasons[] = $smaGapPct >= 0
                ? sprintf('SMA20 is %.1f%% above SMA50 — short-term uptrend', $smaGapPct)
                : sprintf('SMA20 is %.1f%% below SMA50 — short-term downtrend', abs($smaGapPct));
            $reasons[] = sprintf('RSI %.1f', $r['rsi_14']);
            if ($r['macd_histogram'] !== null) {
                $reasons[] = $r['macd_histogram'] >= 0 ? 'MACD histogram positive — bullish momentum building' : 'MACD histogram negative — bearish momentum building';
            }
            if ($r['bb_upper'] !== null && $r['bb_lower'] !== null && $r['close'] !== null) {
                $reasons[] = $bbSigned > 0.3 ? 'price near lower Bollinger Band — potential rebound' : ($bbSigned < -0.3 ? 'price near upper Bollinger Band — potential pullback' : 'price mid-band');
            }

            return [...$r, 'short_term_score' => $score, 'short_term_signal' => $this->classifyTermScore($score), 'reasons' => $reasons];
        })
            ->sortByDesc('short_term_score')
            ->values();
    }

    /**
     * Per-stock snapshot for the "Mid-Term" screen — established trend
     * (price vs SMA50 & SMA200), RSI positioned in a healthy accumulation
     * band rather than an extreme, 6-month price return, and trailing
     * volatility. Targets a multi-week-to-few-months hold, between the
     * fast-moving short-term screen and the buy-and-hold long-term one.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function midTermCandidates(): Collection
    {
        $stocks = Stock::with('latestIndicator')->get();
        $closes = $this->priceChanges();

        $cutoff6m = now()->subDays(182)->toDateString();
        $ranked6m = DB::table('daily_prices')
            ->where('trade_date', '<=', $cutoff6m)
            ->selectRaw('stock_id, close_price, ROW_NUMBER() OVER (PARTITION BY stock_id ORDER BY trade_date DESC) as rn');
        $sixMonthsAgo = DB::query()->fromSub($ranked6m, 'r')->where('rn', 1)->get()->keyBy('stock_id');

        $sinceVol = now()->subDays(365)->toDateString();
        $withPrev = DB::table('daily_prices')
            ->where('trade_date', '>=', $sinceVol)
            ->selectRaw('stock_id, close_price, LAG(close_price) OVER (PARTITION BY stock_id ORDER BY trade_date) as prev_close');
        $dailyReturns = DB::query()->fromSub($withPrev, 'w')
            ->whereNotNull('prev_close')
            ->where('prev_close', '>', 0)
            ->selectRaw('stock_id, ((close_price - prev_close) / prev_close) * 100 as daily_return');
        $volatility = DB::query()->fromSub($dailyReturns, 'r')
            ->groupBy('stock_id')
            ->selectRaw('stock_id, STDDEV_POP(daily_return) as volatility_pct, COUNT(*) as return_days')
            ->get()
            ->keyBy('stock_id');

        $minReturnDays = 60;

        return $stocks->map(function ($stock) use ($closes, $sixMonthsAgo, $volatility, $minReturnDays) {
            $price = $closes->get($stock->id);
            $ind = $stock->latestIndicator;
            $close = $price['close'] ?? null;
            $past = $sixMonthsAgo->get($stock->id);
            $vol = $volatility->get($stock->id);
            $hasEnoughReturns = $vol && (int) $vol->return_days >= $minReturnDays;

            $return6m = ($close !== null && $past && (float) $past->close_price > 0)
                ? round((($close - (float) $past->close_price) / (float) $past->close_price) * 100, 2)
                : null;

            return [
                'stock_id' => $stock->id,
                'symbol' => $stock->symbol,
                'company_name' => $stock->company_name,
                'sector' => $stock->sector,
                'share_group' => $stock->share_group,
                'close' => $close,
                'change_pct' => $price['change_pct'] ?? null,
                'turnover' => $price['turnover'] ?? null,
                'sma_50' => $ind?->sma_50 !== null ? (float) $ind->sma_50 : null,
                'sma_200' => $ind?->sma_200 !== null ? (float) $ind->sma_200 : null,
                'rsi_14' => $ind?->rsi_14 !== null ? (float) $ind->rsi_14 : null,
                'return_6m_pct' => $return6m,
                'volatility_pct' => $hasEnoughReturns && $vol->volatility_pct !== null ? round((float) $vol->volatility_pct, 2) : null,
            ];
        })->values();
    }

    /**
     * Ranks stocks for mid-term positioning — a signed score (-100 to +100,
     * positive = bullish lean): established trend vs SMA50/SMA200 (35%), RSI
     * in a healthy 40-65 accumulation band rather than an extreme (20%),
     * 6-month price return (30%), and trailing-year volatility as a
     * stability factor (15%, neutral when there isn't enough history yet).
     * Gated only on real liquidity and having SMA50/SMA200/RSI to score —
     * like the short-term screen (and unlike long-term), this deliberately
     * keeps bearish stocks in so it can also surface mid-term sell/avoid
     * candidates.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rankMidTermCandidates(?string $sector = null): Collection
    {
        $rows = $this->midTermCandidates();

        if ($sector) {
            $rows = $rows->where('sector', $sector);
        }

        $candidates = $rows->filter(fn ($r) => $r['turnover'] !== null && $r['turnover'] > 0
            && $r['sma_50'] !== null && $r['sma_200'] !== null && $r['rsi_14'] !== null);

        if ($candidates->isEmpty()) {
            return collect();
        }

        $vols = $candidates->pluck('volatility_pct')->filter(fn ($v) => $v !== null);
        $minVol = $vols->isNotEmpty() ? $vols->min() : null;
        $maxVol = $vols->isNotEmpty() ? $vols->max() : null;
        $volRange = ($minVol !== null && $maxVol !== null) ? max($maxVol - $minVol, 0.0001) : null;

        return $candidates->map(function ($r) use ($minVol, $volRange) {
            // How far price sits above/below both SMA50 and SMA200, averaged
            // and capped at +/-15% — an established trend, not a fresh cross.
            $gap50 = $r['sma_50'] > 0 ? (($r['close'] - $r['sma_50']) / $r['sma_50']) * 100 : 0;
            $gap200 = $r['sma_200'] > 0 ? (($r['close'] - $r['sma_200']) / $r['sma_200']) * 100 : 0;
            $trendSigned = max(-1, min(1, (($gap50 + $gap200) / 2) / 15));

            // Peaks at RSI 52.5 (healthy accumulation), turns negative past
            // roughly 30/75 (breakdown risk / overbought risk).
            $rsiSigned = max(-1, min(1, 1 - abs($r['rsi_14'] - 52.5) / 22.5));

            $returnSigned = $r['return_6m_pct'] !== null
                ? max(-1, min(1, $r['return_6m_pct'] / 20))
                : 0;

            // Lower volatility = more positive (stable enough to hold for
            // months); missing data is scored neutral, not penalized.
            $volSigned = ($r['volatility_pct'] !== null && $volRange !== null)
                ? max(-1, min(1, 1 - 2 * (($r['volatility_pct'] - $minVol) / $volRange)))
                : 0;

            $score = round((0.35 * $trendSigned + 0.20 * $rsiSigned + 0.30 * $returnSigned + 0.15 * $volSigned) * 100, 1);

            $reasons = [];
            $reasons[] = $trendSigned >= 0
                ? 'price trading above both SMA50 and SMA200 — established uptrend'
                : 'price trading below both SMA50 and SMA200 — established downtrend';
            $reasons[] = sprintf('RSI %.1f', $r['rsi_14']);
            $reasons[] = $r['return_6m_pct'] !== null
                ? sprintf('%s%.2f%% over 6 months', $r['return_6m_pct'] > 0 ? '+' : '', $r['return_6m_pct'])
                : 'not enough price history yet for a 6-month return';
            $reasons[] = $r['volatility_pct'] !== null
                ? "volatility {$r['volatility_pct']}% (daily stdev, trailing year)"
                : 'not enough price history yet for a volatility read';

            return [...$r, 'mid_term_score' => $score, 'mid_term_signal' => $this->classifyTermScore($score), 'reasons' => $reasons];
        })
            ->sortByDesc('mid_term_score')
            ->values();
    }

    /**
     * Shared buy/sell labeling for the signed short-/mid-term scores — same
     * thresholds (50/30, scaled to the -100..100 range) as
     * SignalGeneratorService::classify(), so the labels and their colors
     * mean the same thing wherever they appear in the app.
     */
    private function classifyTermScore(float $score): string
    {
        return match (true) {
            $score >= 50 => 'strong_buy',
            $score >= 30 => 'buy',
            $score <= -50 => 'strong_sell',
            $score <= -30 => 'sell',
            default => 'hold',
        };
    }
}
