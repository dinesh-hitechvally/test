<?php

namespace App\Services\MarketData;

use App\Models\Signal;
use App\Models\Stock;
use App\Models\StockFundamental;

class SignalGeneratorService
{
    /**
     * How long a golden/death cross has to hold, and how far apart SMA50/
     * SMA200 have to actually get, before it counts as a real trend change
     * rather than noise. Without this, a stock whose SMA50/SMA200 pair is
     * flat and nearly touching (a genuinely sideways period at that
     * timescale) fires a full-weight buy, then a full-weight sell days
     * later, on nothing more than sub-1%-of-price wobble — a real,
     * observed whipsaw (confirmed against CHCL: Aug 4 2026 "golden cross"
     * on a 0.21-point gap, reversed 7 sessions later on a -0.01 gap, net
     * loss). Both thresholds are deliberately modest — this is meant to
     * filter out noise-level crosses, not delay every real one.
     */
    private const CROSS_CONFIRM_DAYS = 3;

    private const CROSS_MIN_GAP_PCT = 0.5;

    /**
     * Recompute and upsert buy/sell/hold signals for a stock's full history,
     * from its already-recalculated technical_indicators + daily_prices.
     */
    public function generate(Stock $stock): int
    {
        $prices = $stock->dailyPrices()->orderBy('trade_date')->get()->keyBy(
            fn ($p) => $p->trade_date->toDateString()
        );
        $indicators = $stock->technicalIndicators()->orderBy('trade_date')->get();
        // Only ever applied to the single latest row below — see that call
        // site's comment for why.
        $fundamental = $stock->fundamental;

        $rows = [];
        $previous = null;
        // Golden/death cross confirmation state — must persist across the
        // whole history, unlike every other rule below which only ever
        // looks at today vs. yesterday.
        $crossState = ['side' => null, 'streak' => 0, 'confirmed' => false];

        foreach ($indicators as $indicator) {
            $date = $indicator->trade_date->toDateString();

            // Need at least SMA20 available before a signal is meaningful.
            if ($indicator->sma_20 === null) {
                $previous = $indicator;

                continue;
            }

            $price = $prices->get($date);
            $close = $price?->close_price !== null ? (float) $price->close_price : null;
            $previousClose = $previous !== null ? $prices->get($previous->trade_date->toDateString()) : null;
            $previousClose = $previousClose?->close_price !== null ? (float) $previousClose->close_price : null;
            [$crossScore, $crossReason, $crossKey] = $this->goldenDeathCross($indicator, $crossState);
            [$ruleScore, $reasons, $ruleKeys] = $this->score($indicator, $previous, $close, $previousClose);

            // Both contributions are raw (weight 2 for the cross, weight 1
            // for everything else) — normalized once here, not inside
            // score(), so the two can be combined on the same scale.
            $score = ($crossScore + $ruleScore) / 6;
            if ($crossReason !== null) {
                array_unshift($reasons, $crossReason);
                array_unshift($ruleKeys, $crossKey);
            }

            $rows[] = [
                'stock_id' => $stock->id,
                'trade_date' => $date,
                'signal' => $this->classify($score),
                'score' => round($score, 4),
                'reasons' => json_encode($reasons ?: ['No strong signals — indicators are neutral']),
                'rule_keys' => json_encode($ruleKeys),
                'price_at_signal' => $price?->close_price,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $previous = $indicator;
        }

        if ($rows === []) {
            return 0;
        }

        // Valuation only ever adjusts the single MOST RECENT row (last in
        // $rows, since $indicators is ordered ascending) — never the whole
        // regenerated history. See valuationTilt()'s docblock for why:
        // StockFundamental only has today's snapshot, so retroactively
        // applying it to every past day would silently corrupt backtested
        // accuracy with data that didn't exist at the time. A first attempt
        // at doing exactly that (applied uniformly across all rows) was
        // measured via signals:backtest-accuracy and DID demonstrably hurt
        // accuracy — strong_buy's win rate dropped from 55.74% to 38.89%
        // (n 61→18) purely from historical rows being diluted with a value
        // that's valid for TODAY, not for THAT DAY. Confining it to the
        // latest row avoids that: SignalAccuracyService's backtest already
        // excludes each stock's most recent `horizon` days (no forward
        // price data to grade them against yet), so this never touches what
        // gets backtested — it only affects the live signal a user actually
        // sees today.
        $lastIndex = array_key_last($rows);
        [$valuationDelta, $valuationReasons, $valuationKeys] = $this->valuationTilt($fundamental);

        if ($valuationReasons !== []) {
            $adjustedScore = (float) $rows[$lastIndex]['score'] + $valuationDelta;
            $existingReasons = array_diff(json_decode($rows[$lastIndex]['reasons'], true), ['No strong signals — indicators are neutral']);
            $existingKeys = json_decode($rows[$lastIndex]['rule_keys'], true);

            $rows[$lastIndex]['score'] = round($adjustedScore, 4);
            $rows[$lastIndex]['signal'] = $this->classify($adjustedScore);
            $rows[$lastIndex]['reasons'] = json_encode(array_values([...$existingReasons, ...$valuationReasons]));
            $rows[$lastIndex]['rule_keys'] = json_encode([...$existingKeys, ...$valuationKeys]);
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            Signal::upsert(
                $chunk,
                uniqueBy: ['stock_id', 'trade_date'],
                update: ['signal', 'score', 'reasons', 'rule_keys', 'updated_at']
            );
        }

        return count($rows);
    }

    /**
     * Each condition below is tagged with its canonical key from
     * SignalRules::RULES as it fires, alongside the human-readable reason —
     * the key is what the rule scanner filters on, the text is what the
     * signal feed displays. Keep the two in sync when editing a condition.
     *
     * Returns the RAW (pre-/6) score — the golden/death cross rule is
     * handled separately by goldenDeathCross() since, unlike every rule
     * here, it needs state carried across the whole history, not just
     * today vs. yesterday. generate() adds the two raw contributions
     * together and normalizes once.
     *
     * The two bearish oscillator rules (RSI, Bollinger) are deliberately NOT
     * the mirror image of their bullish counterparts. They used to be a
     * level test (fire every day RSI>70 / close>=bb_upper holds), same shape
     * as the bullish side. That measurably underperformed baseline in
     * backtesting (see classify()'s docblock) because in a trending market a
     * stock can sit overbought for a week-plus of continued upside — a level
     * test fires on every one of those days as if each were a fresh bearish
     * signal, when it's actually just "still trending up." A rollover/
     * rejection test instead requires the extreme to have already started
     * reversing (RSI dropping back through 70, price closing back inside the
     * upper band after tagging it) before it counts, which is both a single
     * fire per extreme (no more inflating a bucket with repeats of the same
     * event) and closer to what "overbought" is actually supposed to mean —
     * momentum that has already peaked, not momentum that's merely high. The
     * bullish side is left as a level test since it already backtests
     * positive; changing what isn't broken would just add unvalidated risk.
     *
     * @return array{0: float, 1: string[], 2: string[]}
     */
    private function score($today, $yesterday, ?float $close, ?float $previousClose): array
    {
        $score = 0.0;
        $reasons = [];
        $ruleKeys = [];

        // Short-term SMA20/50 crossover (weight 1)
        if ($today->sma_20 !== null && $today->sma_50 !== null
            && $yesterday?->sma_20 !== null && $yesterday?->sma_50 !== null) {
            if ($yesterday->sma_20 <= $yesterday->sma_50 && $today->sma_20 > $today->sma_50) {
                $score += 1;
                $reasons[] = 'SMA20 crossed above SMA50 (short-term bullish)';
                $ruleKeys[] = 'sma_20_50_bull_cross';
            } elseif ($yesterday->sma_20 >= $yesterday->sma_50 && $today->sma_20 < $today->sma_50) {
                $score -= 1;
                $reasons[] = 'SMA20 crossed below SMA50 (short-term bearish)';
                $ruleKeys[] = 'sma_20_50_bear_cross';
            }
        }

        // RSI oversold entry (weight 1, level test — kept as-is, backtests positive)
        if ($today->rsi_14 !== null) {
            $rsi = (float) $today->rsi_14;
            if ($rsi < 30) {
                $score += 1;
                $reasons[] = sprintf('RSI %.1f — oversold', $rsi);
                $ruleKeys[] = 'rsi_oversold';
            }
        }

        // RSI overbought rollover (weight 1): fires once, when RSI drops back
        // through 70 from above — momentum has already turned, not merely
        // "still high."
        if ($today->rsi_14 !== null && $yesterday?->rsi_14 !== null) {
            $rsi = (float) $today->rsi_14;
            $previousRsi = (float) $yesterday->rsi_14;
            if ($previousRsi > 70 && $rsi <= 70) {
                $score -= 1;
                $reasons[] = sprintf('RSI rolled over from overbought (%.1f → %.1f)', $previousRsi, $rsi);
                $ruleKeys[] = 'rsi_overbought';
            }
        }

        // MACD/signal crossover (weight 1)
        if ($today->macd !== null && $today->macd_signal !== null
            && $yesterday?->macd !== null && $yesterday?->macd_signal !== null) {
            if ($yesterday->macd <= $yesterday->macd_signal && $today->macd > $today->macd_signal) {
                $score += 1;
                $reasons[] = 'MACD bullish crossover';
                $ruleKeys[] = 'macd_bull_cross';
            } elseif ($yesterday->macd >= $yesterday->macd_signal && $today->macd < $today->macd_signal) {
                $score -= 1;
                $reasons[] = 'MACD bearish crossover';
                $ruleKeys[] = 'macd_bear_cross';
            }
        }

        // Lower Bollinger touch (weight 1, level test — kept as-is, backtests positive)
        if ($close !== null && $today->bb_lower !== null) {
            if ($close <= (float) $today->bb_lower) {
                $score += 1;
                $reasons[] = 'Price at/below lower Bollinger Band — potential rebound';
                $ruleKeys[] = 'bb_lower_touch';
            }
        }

        // Upper Bollinger rejection (weight 1): fires once, when price closes
        // back inside the band after having tagged/exceeded the upper band
        // the previous day — a rejection, not merely "still up there."
        if ($close !== null && $previousClose !== null && $today->bb_upper !== null && $yesterday?->bb_upper !== null) {
            $upperYesterday = (float) $yesterday->bb_upper;
            $upperToday = (float) $today->bb_upper;
            if ($previousClose >= $upperYesterday && $close < $upperToday) {
                $score -= 1;
                $reasons[] = 'Price rejected from upper Bollinger Band — potential pullback';
                $ruleKeys[] = 'bb_upper_touch';
            }
        }

        return [$score, $reasons, $ruleKeys];
    }

    /**
     * A small, bounded valuation nudge from EPS/P-E/PBV — capped at ±0.23
     * total (well under the 0.3 buy/sell threshold on its own), so it can
     * only tilt an already-close-to-the-line call, never manufacture one
     * from nothing. Called from generate() ONLY for a stock's single most
     * recent row — see the call site's comment for why: StockFundamental
     * only stores each stock's LATEST scraped snapshot (merolagani.com,
     * refreshed weekly — see MeroLaganiFundamentalsService), not a
     * historical time series, so there's no valid, dated value to apply to
     * any day but today. Revisit widening this to full history once enough
     * dated snapshots have accumulated to properly backtest it, the same
     * way CROSS_CONFIRM_DAYS/CROSS_MIN_GAP_PCT and the buy/sell thresholds
     * already were.
     *
     * @return array{0: float, 1: string[], 2: string[]}
     */
    private function valuationTilt(?StockFundamental $fundamental): array
    {
        if ($fundamental === null || $fundamental->eps === null || $fundamental->pe_ratio === null || $fundamental->pbv === null) {
            return [0.0, [], []];
        }

        $eps = (float) $fundamental->eps;
        $pe = (float) $fundamental->pe_ratio;
        $pbv = (float) $fundamental->pbv;

        // A P/E on negative earnings is meaningless — stop here rather than
        // also reading it below.
        if ($eps <= 0) {
            return [-0.15, [sprintf('Reporting a loss (EPS Rs. %.2f) — valuation caution', $eps)], ['valuation_loss']];
        }

        $delta = 0.0;
        $reasons = [];
        $keys = [];

        if ($pbv < 1) {
            $delta += 0.08;
            $reasons[] = sprintf('Trading below book value (PBV %.2f)', $pbv);
            $keys[] = 'valuation_undervalued';
        } elseif ($pbv > 4) {
            $delta -= 0.08;
            $reasons[] = sprintf('Trading well above book value (PBV %.2f)', $pbv);
            $keys[] = 'valuation_overvalued';
        }

        if ($pe > 0 && $pe < 10) {
            $delta += 0.08;
            $reasons[] = sprintf('Cheap earnings multiple (P/E %.2f)', $pe);
            $keys[] = 'valuation_cheap_pe';
        } elseif ($pe > 40) {
            $delta -= 0.08;
            $reasons[] = sprintf('Expensive earnings multiple (P/E %.2f)', $pe);
            $keys[] = 'valuation_expensive_pe';
        }

        return [$delta, $reasons, $keys];
    }

    /**
     * A golden/death cross only counts once SMA50/SMA200 have sat on the
     * new side of each other for CROSS_CONFIRM_DAYS running AND separated
     * by at least CROSS_MIN_GAP_PCT of price — see the class docblock
     * constants for why. $state is carried across the whole history by the
     * caller (generate()) and mutated in place: side (which side SMA50 is
     * currently on), streak (consecutive days on that side), and confirmed
     * (whether this particular streak has already fired, so it fires
     * exactly once per crossing, not every day the gap stays wide).
     *
     * @param  array{side: ?string, streak: int, confirmed: bool}  $state
     * @return array{0: float, 1: ?string, 2: ?string}
     */
    private function goldenDeathCross($today, array &$state): array
    {
        if ($today->sma_50 === null || $today->sma_200 === null || (float) $today->sma_200 === 0.0) {
            return [0.0, null, null];
        }

        $sma50 = (float) $today->sma_50;
        $sma200 = (float) $today->sma_200;
        $side = $sma50 > $sma200 ? 'above' : ($sma50 < $sma200 ? 'below' : $state['side']);

        if ($side !== $state['side']) {
            $state['side'] = $side;
            $state['streak'] = 1;
            $state['confirmed'] = false;
        } else {
            $state['streak']++;
        }

        if ($state['confirmed'] || $state['streak'] < self::CROSS_CONFIRM_DAYS) {
            return [0.0, null, null];
        }

        $gapPct = abs($sma50 - $sma200) / $sma200 * 100;

        if ($gapPct < self::CROSS_MIN_GAP_PCT) {
            return [0.0, null, null];
        }

        $state['confirmed'] = true;

        return $side === 'above'
            ? [2.0, sprintf('Golden cross: SMA50 has held %.2f%% above SMA200 for %d+ days (long-term bullish)', $gapPct, self::CROSS_CONFIRM_DAYS), 'golden_cross']
            : [-2.0, sprintf('Death cross: SMA50 has held %.2f%% below SMA200 for %d+ days (long-term bearish)', $gapPct, self::CROSS_CONFIRM_DAYS), 'death_cross'];
    }

    /**
     * Thresholds are set in terms of raw (pre-/6) rule weight so they're easy
     * to reason about: 0.3 ≈ 2 points (two weight-1 rules agreeing, or one
     * weight-2 cross alone), 0.5 ≈ 3 points. A single weight-1 rule alone
     * (1/6 = 0.167) no longer clears the buy/sell bar on its own — backtesting
     * showed single-indicator triggers accounted for ~99.97% of directional
     * calls and dragged accuracy below the "did price just keep drifting"
     * baseline, so a directional signal now requires real confluence.
     *
     * The buy/sell bar was previously ASYMMETRIC (buy fires at raw>=2, sell
     * only at raw<=-3) because a chronological 70/30 backtest found that
     * raw=-2 (mostly the RSI-overbought + upper-Bollinger-Band combo, ~90%
     * of that bucket) underperformed baseline while its bullish mirror
     * beat it. The cause turned out to be the shape of the bearish rules
     * themselves, not the threshold: rsi_overbought/bb_upper_touch were a
     * level test that fired on every day a stock sat overbought, which in a
     * trending market mostly means "still going up," not "about to fall."
     * score() now fires those two rules on rollover/rejection instead (see
     * its own docblock) — re-run `signals:backtest-accuracy` after that
     * change and confirm the 'sell' bucket clears baseline before trusting
     * this threshold in production; if it still doesn't, tighten this back
     * to raw<=-3 rather than shipping a coin-flip-or-worse bearish tier
     * again.
     */
    private function classify(float $score): string
    {
        return match (true) {
            $score >= 0.5 => 'strong_buy',
            $score >= 0.3 => 'buy',
            $score <= -0.5 => 'strong_sell',
            $score <= -0.3 => 'sell',
            default => 'hold',
        };
    }
}
