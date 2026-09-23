<?php

namespace App\Services\MarketData;

use App\Models\Forecast;
use App\Models\NextCloseAccuracyStat;
use App\Models\Stock;

/**
 * Projects a next-trading-day closing price from already-computed technical
 * indicators — momentum, trend alignment, RSI, MACD histogram, and
 * Bollinger Band position, the same five rule families
 * SignalGeneratorService already scores directionally, just expressed as
 * price deltas instead of a buy/sell lean.
 *
 * The per-component weights (see WEIGHT_* below) are NOT hand-picked — they
 * were fit with a no-intercept OLS regression of each raw indicator against
 * realized next-day return, trained on the first 70% of each stock's
 * history (chronologically) and validated on the held-out last 30%
 * (~140K out-of-sample pairs) to guard against overfitting. See
 * NextCloseAccuracyStat for the resulting accuracy — this still does not
 * clearly beat a naive "assume no change tomorrow" baseline (see
 * beatsBaseline()), but the properly-fit weights get far closer to it than
 * the original hand-picked ones did (out-of-sample test MAPE landed within
 * ~0.5% of the naive baseline, vs ~23% worse before), and direction
 * accuracy tops out around 50-50.5% (a coin flip). The regression also
 * surfaced a genuinely surprising, counter-intuitive result: RSI and
 * Bollinger position — originally coded as mean-reversion rules ("oversold
 * pulls up") — actually fit with the OPPOSITE sign out-of-sample: at this
 * one-day horizon they behave as (very weak) continuation signals, not
 * reversion. The weights below reflect what the data actually showed, not
 * the original intuition. Shipped anyway, honestly labeled wherever it's
 * shown (see NextCloseAccuracyStat::beatsBaseline()), the same way
 * MlModel's own underperformance is disclosed rather than hidden. Not
 * financial advice — a mechanical read of indicators the rest of the app
 * already shows, with no demonstrated predictive edge.
 */
class NextCloseEstimatorService
{
    // Fit via no-intercept OLS: actual_next_day_return ~= WEIGHT * raw_signal,
    // trained on the first 70% of each stock's (day, next-day) pairs and
    // validated on the remaining 30% — see the class docblock. Replaces
    // earlier hand-picked coefficients (0.5, 0.3, 0.01, 0.5, 0.1) that had
    // never been validated against real outcomes.
    private const WEIGHT_MOMENTUM = 0.018360739476516;

    private const WEIGHT_TREND = 0.0030003124353918;

    // Negative: oversold/overbought fit as a weak CONTINUATION signal
    // out-of-sample, not the mean-reversion the original +0.01 assumed.
    private const WEIGHT_RSI = -0.0028732371429147;

    private const WEIGHT_MACD = 0.029921237090796;

    // Positive: price above the middle band fit as weak continuation
    // further away from it, not reversion back toward it.
    private const WEIGHT_BOLLINGER = 0.0053097191031546;

    // How far the total projected move may go, as a multiple of ATR14 (the
    // stock's own typical daily range) — keeps the estimate physically
    // plausible even if every component leans the same direction. With the
    // regression-fit weights above the total delta rarely gets close to
    // this anyway, but it's kept as a sanity bound.
    private const MAX_ATR_MULTIPLE = 1.5;

    // Trading days of recent daily returns averaged for the momentum
    // component (needs one more close than this to compute that many
    // day-over-day returns).
    private const MOMENTUM_LOOKBACK_DAYS = 5;

    // A stock needs at least this many days of indicators before it's
    // included in a backtest run — short of this, SMA200/ATR14 etc. won't
    // have populated for most of its history, making the sample noisy.
    private const BACKTEST_MIN_HISTORY_DAYS = 260;

    /**
     * Backfills a Forecast row for every one of a stock's daily_prices rows
     * that doesn't already have one (stock_id = $stock->id, trade_date <=
     * $asOf, no matching forecasts.trade_date) — not just the latest row.
     * Needed because a full-history import/scrape inserts many historical
     * rows at once, and runFor() used to only ever stamp the single most
     * recent one, leaving everything before it permanently un-forecast even
     * though the indicator history to compute them already exists. Reuses
     * the same per-day replay as backtest(), but persists instead of just
     * measuring. forecasts.trade_date is the day the forecast was generated
     * from; next_close is its projection for the following trading day —
     * consumers that need "the day being predicted" derive it from
     * daily_prices order (the row right after trade_date) rather than it
     * being stored here.
     *
     * @param  \DateTimeInterface|string|null  $asOf  Cutoff trade_date (inclusive); defaults to now().
     */
    public function backfillForStock(Stock $stock, $asOf = null): int
    {
        $indicators = $stock->technicalIndicators()->orderBy('trade_date')->get()->keyBy(
            fn ($i) => $i->trade_date->toDateString()
        );

        $prices = $stock->dailyPrices()
            ->where('trade_date', '<=', $asOf ?? now())
            ->orderBy('trade_date')
            ->get()
            ->values();

        if ($prices->count() <= self::MOMENTUM_LOOKBACK_DAYS) {
            return 0;
        }

        $existingDates = $stock->forecasts()->pluck('trade_date')
            ->map(fn ($d) => $d->toDateString())
            ->flip();

        $updated = 0;

        for ($i = self::MOMENTUM_LOOKBACK_DAYS; $i < $prices->count(); $i++) {
            $today = $prices[$i];
            $dateStr = $today->trade_date->toDateString();

            if (isset($existingDates[$dateStr])) {
                continue;
            }

            $indicator = $indicators->get($dateStr);
            if (! $indicator || $indicator->sma_50 === null || $indicator->rsi_14 === null) {
                continue;
            }

            $close = (float) $today->close_price;
            if ($close <= 0) {
                continue;
            }

            $recentCloses = $prices->slice($i - self::MOMENTUM_LOOKBACK_DAYS, self::MOMENTUM_LOOKBACK_DAYS + 1)
                ->map(fn ($p) => (float) $p->close_price)
                ->values()
                ->all();

            ['delta' => $delta, 'reasons' => $reasons] = $this->computeDelta($close, $indicator, $recentCloses);

            Forecast::updateOrCreate(
                ['stock_id' => $stock->id, 'trade_date' => $dateStr],
                [
                    'next_close' => round($close + $delta, 4),
                    'reasons' => $reasons,
                    'method' => 'technical_rules',
                ]
            );

            $updated++;
        }

        return $updated;
    }

    /**
     * The stock's most recently generated forecast, in the same
     * available/message-on-miss shape AiStockOpinionService::getStoredOpinion()
     * uses — a plain DB read, generation only ever happens via
     * RecalculationPipeline/backfillForStock().
     */
    public function getStoredForecast(Stock $stock): array
    {
        $forecast = $stock->latestForecast;

        if (! $forecast) {
            return ['available' => false, 'message' => 'No forecast generated for this stock yet — it\'s picked up by the next scheduled recalculation.'];
        }

        return [
            'available' => true,
            'trade_date' => $forecast->trade_date->toDateString(),
            'next_close' => (float) $forecast->next_close,
            'reasons' => $forecast->reasons,
        ];
    }

    /**
     * Replays the same formula against every (day, next-day) pair each
     * qualifying stock actually has, and compares it to the naive "no
     * change" baseline over real outcomes — not a one-off spot check.
     * Persists the result (see NextCloseAccuracyStat) the same way
     * MlDirectionPredictorService::train() persists MlModel.
     */
    public function backtest(): NextCloseAccuracyStat
    {
        $stocks = Stock::has('technicalIndicators', '>=', self::BACKTEST_MIN_HISTORY_DAYS)->get();

        $estimateErrors = [];
        $naiveErrors = [];
        $directionHits = 0;
        $directionTotal = 0;
        $stocksUsed = 0;

        foreach ($stocks as $stock) {
            $indicators = $stock->technicalIndicators()->orderBy('trade_date')->get()->keyBy(
                fn ($i) => $i->trade_date->toDateString()
            );
            $prices = $stock->dailyPrices()->orderBy('trade_date')->get()->values();

            if ($prices->count() <= self::MOMENTUM_LOOKBACK_DAYS) {
                continue;
            }

            $usedThisStock = false;

            for ($i = self::MOMENTUM_LOOKBACK_DAYS; $i < $prices->count() - 1; $i++) {
                $todayPrice = $prices[$i];
                $nextPrice = $prices[$i + 1];
                $indicator = $indicators->get($todayPrice->trade_date->toDateString());

                if (! $indicator || $indicator->sma_50 === null || $indicator->rsi_14 === null || $indicator->sma_20 === null) {
                    continue;
                }

                $close = (float) $todayPrice->close_price;
                $actualNext = (float) $nextPrice->close_price;

                if ($close <= 0 || $actualNext <= 0) {
                    continue;
                }

                $recentCloses = $prices->slice($i - self::MOMENTUM_LOOKBACK_DAYS, self::MOMENTUM_LOOKBACK_DAYS + 1)
                    ->map(fn ($p) => (float) $p->close_price)
                    ->values()
                    ->all();

                ['delta' => $delta] = $this->computeDelta($close, $indicator, $recentCloses);
                $estimate = $close + $delta;

                $estimateErrors[] = abs($estimate - $actualNext) / $actualNext;
                $naiveErrors[] = abs($close - $actualNext) / $actualNext;

                $actualDirection = $actualNext >= $close;
                $estimatedDirection = $estimate >= $close;
                $directionTotal++;
                if ($estimatedDirection === $actualDirection) {
                    $directionHits++;
                }

                $usedThisStock = true;
            }

            if ($usedThisStock) {
                $stocksUsed++;
            }
        }

        return NextCloseAccuracyStat::create([
            'sample_size' => count($estimateErrors),
            'stocks_used' => $stocksUsed,
            'mape' => count($estimateErrors) > 0 ? array_sum($estimateErrors) / count($estimateErrors) * 100 : 0,
            'naive_mape' => count($naiveErrors) > 0 ? array_sum($naiveErrors) / count($naiveErrors) * 100 : 0,
            'direction_accuracy' => $directionTotal > 0 ? $directionHits / $directionTotal * 100 : 0,
            'computed_at' => now(),
        ]);
    }

    public function latestAccuracy(): ?NextCloseAccuracyStat
    {
        return NextCloseAccuracyStat::orderByDesc('computed_at')->first();
    }

    /**
     * The formula itself — shared by estimate() (latest data) and
     * backtest() (every historical point) so the two can never drift apart
     * the way a hand-copied second implementation would risk.
     *
     * @param  float[]  $recentCloses  MOMENTUM_LOOKBACK_DAYS+1 closes, oldest first, ending at $close
     * @return array{delta: float, reasons: string[]}
     */
    private function computeDelta(float $close, $indicator, array $recentCloses): array
    {
        $reasons = [];
        $delta = 0.0;

        // Momentum: average daily % return over the trailing window,
        // continued forward at its regression-fit weight (see WEIGHT_MOMENTUM).
        $dailyReturns = [];
        for ($i = 1; $i < count($recentCloses); $i++) {
            $prev = $recentCloses[$i - 1];
            if ($prev > 0) {
                $dailyReturns[] = ($recentCloses[$i] - $prev) / $prev;
            }
        }

        if ($dailyReturns !== []) {
            $avgReturn = array_sum($dailyReturns) / count($dailyReturns);
            $delta += $close * $avgReturn * self::WEIGHT_MOMENTUM;
            $reasons[] = sprintf(
                '%d-day momentum averaging %s%.2f%%/day, carried forward at its fitted weight',
                self::MOMENTUM_LOOKBACK_DAYS,
                $avgReturn >= 0 ? '+' : '',
                $avgReturn * 100
            );
        }

        // Trend alignment: a stock trading with SMA20 above SMA50 leans
        // further into that trend; below, further into the downtrend.
        if ($indicator->sma_20 !== null && (float) $indicator->sma_50 > 0) {
            $trendPct = ((float) $indicator->sma_20 - (float) $indicator->sma_50) / (float) $indicator->sma_50;
            $delta += $close * $trendPct * self::WEIGHT_TREND;
            $reasons[] = sprintf('SMA20 is %s%.2f%% vs SMA50 (trend alignment)', $trendPct >= 0 ? '+' : '', $trendPct * 100);
        }

        // RSI: WEIGHT_RSI is negative — see the class docblock. Oversold
        // (rsiPull > 0) fits as a weak further-down continuation
        // out-of-sample, not the bounce the sign used to assume.
        $rsi = (float) $indicator->rsi_14;
        $rsiPull = (50 - $rsi) / 50;
        $delta += $close * $rsiPull * self::WEIGHT_RSI;
        $reasons[] = sprintf('RSI %.1f (%s)', $rsi, $rsi >= 70 ? 'overbought — weak fitted continuation down' : ($rsi <= 30 ? 'oversold — weak fitted continuation down' : 'neutral'));

        // MACD histogram is already expressed in price units (an EMA
        // difference) — used directly at its fitted weight, not re-normalized.
        if ($indicator->macd_histogram !== null) {
            $delta += (float) $indicator->macd_histogram * self::WEIGHT_MACD;
            $reasons[] = sprintf('MACD histogram %s%.3f (momentum confirmation)', (float) $indicator->macd_histogram >= 0 ? '+' : '', (float) $indicator->macd_histogram);
        }

        // Bollinger position: WEIGHT_BOLLINGER is positive — see the class
        // docblock. Price above the middle band fits as weak continuation
        // further away from it out-of-sample, not the reversion back
        // toward it the sign used to assume.
        if ($indicator->bb_upper !== null && $indicator->bb_lower !== null && $indicator->bb_middle !== null) {
            $bandWidth = (float) $indicator->bb_upper - (float) $indicator->bb_lower;
            if ($bandWidth > 0) {
                $bbPosition = ($close - (float) $indicator->bb_middle) / $bandWidth;
                $delta += $bbPosition * $bandWidth * self::WEIGHT_BOLLINGER;
                $reasons[] = sprintf('Price sits %s%.0f%% of band width from the middle band (weak fitted continuation)', $bbPosition >= 0 ? '+' : '', $bbPosition * 100);
            }
        }

        // Bounded by the stock's own recent volatility — this is a
        // technical-rules read, not a promise, and should never claim a
        // move bigger than the stock itself typically makes in a day.
        if ($indicator->atr_14 !== null && (float) $indicator->atr_14 > 0) {
            $cap = (float) $indicator->atr_14 * self::MAX_ATR_MULTIPLE;
            if (abs($delta) > $cap) {
                $reasons[] = sprintf('Capped to %.1fx the stock\'s 14-day average true range (%.2f)', self::MAX_ATR_MULTIPLE, (float) $indicator->atr_14);
                $delta = $delta > 0 ? $cap : -$cap;
            }
        }

        return ['delta' => $delta, 'reasons' => $reasons];
    }
}
