<?php

namespace App\Services\Reports;

use App\Models\Stock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Investment Horizon pages: long-, mid- and short-term candidate
 * rankings, each scored from its own mix of trend, momentum, volatility and
 * dividend history.
 */
class InvestmentHorizonService
{
    public function __construct(private readonly PriceStatisticsService $prices) {}

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
    private function longTermCandidates(): Collection
    {
        $stocks = Stock::with(['sector', 'latestSignal'])->get();
        $closes = $this->prices->priceChanges();

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
                'sector' => $stock->sector?->name,
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
        $candidates = $rows->filter(fn ($r) => $r['latest_signal'] !== 'sell'
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
    private function shortTermCandidates(): Collection
    {
        $stocks = Stock::with(['sector', 'latestIndicator'])->get();
        $closes = $this->prices->priceChanges();

        return $stocks->map(function ($stock) use ($closes) {
            $price = $closes->get($stock->id);
            $ind = $stock->latestIndicator;

            return [
                'stock_id' => $stock->id,
                'symbol' => $stock->symbol,
                'company_name' => $stock->company_name,
                'sector' => $stock->sector?->name,
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
    private function midTermCandidates(): Collection
    {
        $stocks = Stock::with(['sector', 'latestIndicator'])->get();
        $closes = $this->prices->priceChanges();

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
                'sector' => $stock->sector?->name,
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
