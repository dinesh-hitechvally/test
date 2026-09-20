<?php

namespace App\Services\MarketData;

use App\Models\Signal;
use App\Models\Stock;

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
            [$crossScore, $crossReason, $crossKey] = $this->goldenDeathCross($indicator, $crossState);
            [$ruleScore, $reasons, $ruleKeys] = $this->score($indicator, $previous, $close);

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
     * @return array{0: float, 1: string[], 2: string[]}
     */
    private function score($today, $yesterday, ?float $close): array
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

        // RSI overbought/oversold (weight 1)
        if ($today->rsi_14 !== null) {
            $rsi = (float) $today->rsi_14;
            if ($rsi < 30) {
                $score += 1;
                $reasons[] = sprintf('RSI %.1f — oversold', $rsi);
                $ruleKeys[] = 'rsi_oversold';
            } elseif ($rsi > 70) {
                $score -= 1;
                $reasons[] = sprintf('RSI %.1f — overbought', $rsi);
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

        // Bollinger Band extremes (weight 1)
        if ($close !== null && $today->bb_upper !== null && $today->bb_lower !== null) {
            if ($close <= (float) $today->bb_lower) {
                $score += 1;
                $reasons[] = 'Price at/below lower Bollinger Band — potential rebound';
                $ruleKeys[] = 'bb_lower_touch';
            } elseif ($close >= (float) $today->bb_upper) {
                $score -= 1;
                $reasons[] = 'Price at/above upper Bollinger Band — potential pullback';
                $ruleKeys[] = 'bb_upper_touch';
            }
        }

        return [$score, $reasons, $ruleKeys];
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
