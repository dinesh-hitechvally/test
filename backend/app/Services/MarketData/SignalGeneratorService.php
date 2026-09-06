<?php

namespace App\Services\MarketData;

use App\Models\Signal;
use App\Models\Stock;

class SignalGeneratorService
{
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

        foreach ($indicators as $indicator) {
            $date = $indicator->trade_date->toDateString();

            // Need at least SMA20 available before a signal is meaningful.
            if ($indicator->sma_20 === null) {
                $previous = $indicator;

                continue;
            }

            $price = $prices->get($date);
            $close = $price?->close_price !== null ? (float) $price->close_price : null;
            [$score, $reasons, $ruleKeys] = $this->score($indicator, $previous, $close);

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
     * @return array{0: float, 1: string[], 2: string[]}
     */
    private function score($today, $yesterday, ?float $close): array
    {
        $score = 0.0;
        $reasons = [];
        $ruleKeys = [];

        // Golden / death cross: SMA50 vs SMA200 (weight 2)
        if ($today->sma_50 !== null && $today->sma_200 !== null
            && $yesterday?->sma_50 !== null && $yesterday?->sma_200 !== null) {
            if ($yesterday->sma_50 <= $yesterday->sma_200 && $today->sma_50 > $today->sma_200) {
                $score += 2;
                $reasons[] = 'Golden cross: SMA50 crossed above SMA200 (long-term bullish)';
                $ruleKeys[] = 'golden_cross';
            } elseif ($yesterday->sma_50 >= $yesterday->sma_200 && $today->sma_50 < $today->sma_200) {
                $score -= 2;
                $reasons[] = 'Death cross: SMA50 crossed below SMA200 (long-term bearish)';
                $ruleKeys[] = 'death_cross';
            }
        }

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

        return [$score / 6, $reasons, $ruleKeys];
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
