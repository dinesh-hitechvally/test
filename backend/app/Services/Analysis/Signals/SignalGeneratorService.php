<?php

namespace App\Services\Analysis\Signals;

use App\Models\Signal;
use App\Models\SignalBreakdown;
use App\Models\Stock;
use App\Models\StockFundamental;

class SignalGeneratorService
{
    public function __construct(private SignalConditionScorer $scorer) {}

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

    /** The two rules that say "oversold, expect a rebound". Alone they are a reading, not a buy (see applyContext()). */
    private const DIP_RULES = ['rsi_oversold', 'bb_lower_touch'];

    /** SMA50 is "rising" / "falling" when it is above / below where it was this many sessions ago. */
    private const TREND_SLOPE_DAYS = 10;

    /**
     * How many bounce confirmations a dip-buy needs, by trend. In an uptrend a dip is the normal pullback and needs
     * none; in a downtrend a stock can stay oversold for weeks while it keeps falling, so it must have started
     * to turn first.
     */
    private const CONFIRMATIONS_NEEDED = ['uptrend' => 0, 'sideways' => 1, 'unknown' => 1, 'downtrend' => 2];

    /**
     * Recompute and upsert buy/sell/hold signals (and the percentage breakdown behind each) for a stock's
     * full history, from its already-recalculated technical_indicators + daily_prices.
     */
    public function generate(Stock $stock): int
    {
        $rows = $this->buildRows($stock);

        if ($rows === []) {
            return 0;
        }

        $breakdowns = array_column($rows, 'breakdown');
        $rows = array_map(function ($row) {
            unset($row['breakdown']);

            return $row;
        }, $rows);

        foreach (array_chunk($rows, 500) as $chunk) {
            Signal::upsert(
                $chunk,
                uniqueBy: ['stock_id', 'trade_date'],
                update: ['signal', 'score', 'reasons', 'rule_keys', 'updated_at']
            );
        }

        // One breakdown per stock per day, keyed like the signals themselves.
        $links = [];

        foreach ($rows as $i => $row) {
            $links[] = ['stock_id' => $stock->id, 'trade_date' => $row['trade_date'], ...$breakdowns[$i]];
        }

        foreach (array_chunk($links, 500) as $chunk) {
            SignalBreakdown::upsert(
                $chunk,
                uniqueBy: ['stock_id', 'trade_date'],
                update: array_values(array_diff(array_keys($links[0] ?? []), ['stock_id', 'trade_date']))
            );
        }

        return count($rows);
    }

    /**
     * Works out every day's signal for a stock without saving anything — generate() saves the result.
     * Each row carries a 'breakdown' entry (the percentages behind the decision) that generate() stores separately.
     * $withContext = false skips the bounce-confirmation layer (see applyContext()); it exists only so the two
     * behaviours can be compared on the same data.
     *
     * @return list<array<string, mixed>>
     */
    public function buildRows(Stock $stock, bool $withContext = true): array
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
        $lastCtx = null;
        // Golden/death cross confirmation state — must persist across the
        // whole history, unlike every other rule below which only ever
        // looks at today vs. yesterday.
        $crossState = ['side' => null, 'streak' => 0, 'confirmed' => false];

        foreach ($indicators as $k => $indicator) {
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
            $earlier = $indicators->get($k - self::TREND_SLOPE_DAYS);
            $earlierClose = $earlier !== null ? $prices->get($earlier->trade_date->toDateString()) : null;
            $earlierClose = $earlierClose?->close_price !== null ? (float) $earlierClose->close_price : null;
            [$crossReason, $crossKey] = $this->goldenDeathCross($indicator, $crossState);
            [$reasons, $ruleKeys] = $this->detectRules($indicator, $previous, $close, $previousClose);

            if ($crossReason !== null) {
                array_unshift($reasons, $crossReason);
                array_unshift($ruleKeys, $crossKey);
            }

            // Every fired rule is recorded (reasons + rule_keys, so the feed and the rule scanner see all of
            // them). A dip-buy rule only votes when the trend and a bounce back it (applyContext): a reading
            // alone is not a decision.
            $scoringKeys = $ruleKeys;

            if ($withContext) {
                [$scoringKeys, $contextReasons] = $this->applyContext($ruleKeys, $indicator, $previous, $earlier, $close, $previousClose);
                array_push($reasons, ...$contextReasons);
            }

            // Conditions -> category % -> final BUY / SELL / HOLD %. The decision is an output of that,
            // not an input (see SignalConditionScorer and config/signals.php).
            $ctx = [
                'today' => $indicator,
                'yesterday' => $previous,
                'earlier' => $earlier,
                'close' => $close,
                'prev_close' => $previousClose,
                'close_10d_ago' => $earlierClose,
                'percent_b' => $this->percentB($close, $indicator),
                'rule_keys' => $ruleKeys,
                'scoring_keys' => $scoringKeys,
                'dip_suppressed' => array_intersect($ruleKeys, self::DIP_RULES) !== [] && array_intersect($scoringKeys, self::DIP_RULES) === [],
                'fundamental' => null, // latest row only — see below
            ];
            $lastCtx = $ctx;
            $result = $this->scorer->evaluate($ctx);

            if ($result['guard'] !== null) {
                $reasons[] = $result['guard'];
            }

            if ($result['decision'] !== 'hold') {
                $reasons[] = $this->scorer->explain($result);
            }

            $rows[] = [
                'stock_id' => $stock->id,
                'trade_date' => $date,
                'signal' => $result['decision'],
                'score' => round(($result['final']['buy'] - $result['final']['sell']) / 100, 4),
                'reasons' => json_encode($reasons ?: ['No strong signals — indicators are neutral']),
                'rule_keys' => json_encode($ruleKeys),
                'updated_at' => now(),
                'breakdown' => $this->breakdownRow($result),
            ];

            $previous = $indicator;
        }

        if ($rows === []) {
            return [];
        }

        // Fundamental and valuation only ever apply to the single MOST RECENT row
        // (last in $rows, since $indicators is ascending) — never the whole
        // regenerated history. StockFundamental only holds today's snapshot,
        // so applying it to past days would put information that did not exist
        // then into the backtest (an earlier attempt at exactly that dropped
        // strong_buy's win rate from 55.74% to 38.89%). SignalAccuracyService
        // already leaves out each stock's most recent `horizon` days (nothing
        // to grade them against yet), so this never touches what gets
        // backtested — it only shapes the live signal shown today.
        $lastIndex = array_key_last($rows);
        $lastCtx['fundamental'] = $fundamental;
        $result = $this->scorer->evaluate($lastCtx);

        $reasons = array_values(array_filter(
            json_decode($rows[$lastIndex]['reasons'], true),
            fn ($r) => $r !== 'No strong signals — indicators are neutral' && ! preg_match('/^(BUY|SELL|HOLD): BUY |^Decision (BUY|SELL) — /', $r) && $r !== ($result['guard'] ?? null)
        ));
        $ruleKeys = json_decode($rows[$lastIndex]['rule_keys'], true);

        // Rule keys are kept for the rule scanner (valuationTilt only supplies the readable lines + keys now).
        [, $valuationReasons, $valuationKeys] = $this->valuationTilt($fundamental);
        array_push($reasons, ...$valuationReasons);

        if ($result['guard'] !== null) {
            $reasons[] = $result['guard'];
        }

        if ($result['decision'] !== 'hold') {
            $reasons[] = $this->scorer->explain($result);
        }

        $rows[$lastIndex]['score'] = round(($result['final']['buy'] - $result['final']['sell']) / 100, 4);
        $rows[$lastIndex]['signal'] = $result['decision'];
        $rows[$lastIndex]['reasons'] = json_encode($reasons ?: ['No strong signals — indicators are neutral']);
        $rows[$lastIndex]['rule_keys'] = json_encode([...$ruleKeys, ...$valuationKeys]);
        $rows[$lastIndex]['breakdown'] = $this->breakdownRow($result);

        return $rows;
    }

    /**
     * The signal_breakdowns columns for one day: the final percentages and hold type, plus the category
     * percentages, hold scores and every condition. Stored for every day so any past date can be opened and
     * explained, and so the signal backtest can band on the percentages.
     */
    private function breakdownRow(array $result): array
    {
        $row = [
            'buy_pct' => $result['final']['buy'],
            'sell_pct' => $result['final']['sell'],
            'hold_pct' => $result['final']['hold'],
            'hold_type' => $result['hold_type'],
            'conditions' => json_encode($result['conditions']),
        ];

        foreach ($result['hold_scores'] as $type => $score) {
            $row["hold_score_{$type}"] = $score;
        }

        // technical_buy / technical_sell / technical_hold, fundamental_buy, ... — null when the category had no data.
        foreach (SignalConditionScorer::CATEGORIES as $category) {
            foreach (['buy', 'sell', 'hold'] as $side) {
                $row["{$category}_{$side}"] = $result['categories'][$category][$side] ?? null;
            }
        }

        return $row;
    }

    /**
     * Each condition below is tagged with its canonical key from
     * SignalRules::RULES as it fires, alongside the human-readable reason —
     * the key is what the rule scanner filters on, the text is what the
     * signal feed displays. Keep the two in sync when editing a condition.
     *
     * Only DETECTS which rules fired — how much each one counts is
     * SignalRules::RULES' 'weight', applied by generate(). The golden/death
     * cross rule is detected separately by goldenDeathCross() since, unlike
     * every rule here, it needs state carried across the whole history, not
     * just today vs. yesterday.
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
     * @return array{0: string[], 1: string[]} reasons, rule keys
     */
    private function detectRules($today, $yesterday, ?float $close, ?float $previousClose): array
    {
        $reasons = [];
        $ruleKeys = [];

        // Short-term SMA20/50 crossover
        if ($today->sma_20 !== null && $today->sma_50 !== null
            && $yesterday?->sma_20 !== null && $yesterday?->sma_50 !== null) {
            if ($yesterday->sma_20 <= $yesterday->sma_50 && $today->sma_20 > $today->sma_50) {
                $reasons[] = 'SMA20 crossed above SMA50 (short-term bullish)';
                $ruleKeys[] = 'sma_20_50_bull_cross';
            } elseif ($yesterday->sma_20 >= $yesterday->sma_50 && $today->sma_20 < $today->sma_50) {
                $reasons[] = 'SMA20 crossed below SMA50 (short-term bearish)';
                $ruleKeys[] = 'sma_20_50_bear_cross';
            }
        }

        // RSI oversold entry (level test)
        if ($today->rsi_14 !== null) {
            $rsi = (float) $today->rsi_14;
            if ($rsi < 30) {
                $reasons[] = sprintf('RSI %.1f — oversold', $rsi);
                $ruleKeys[] = 'rsi_oversold';
            }
        }

        // RSI overbought rollover: fires once, when RSI drops back
        // through 70 from above — momentum has already turned, not merely
        // "still high."
        if ($today->rsi_14 !== null && $yesterday?->rsi_14 !== null) {
            $rsi = (float) $today->rsi_14;
            $previousRsi = (float) $yesterday->rsi_14;
            if ($previousRsi > 70 && $rsi <= 70) {
                $reasons[] = sprintf('RSI rolled over from overbought (%.1f → %.1f)', $previousRsi, $rsi);
                $ruleKeys[] = 'rsi_overbought';
            }
        }

        // MACD/signal crossover
        if ($today->macd !== null && $today->macd_signal !== null
            && $yesterday?->macd !== null && $yesterday?->macd_signal !== null) {
            if ($yesterday->macd <= $yesterday->macd_signal && $today->macd > $today->macd_signal) {
                $reasons[] = 'MACD bullish crossover';
                $ruleKeys[] = 'macd_bull_cross';
            } elseif ($yesterday->macd >= $yesterday->macd_signal && $today->macd < $today->macd_signal) {
                $reasons[] = 'MACD bearish crossover';
                $ruleKeys[] = 'macd_bear_cross';
            }
        }

        $percentB = $this->percentB($close, $today);
        $previousPercentB = $this->percentB($previousClose, $yesterday);

        // Bollinger %B at/below 0 = close on or under the lower band
        // (level test).
        if ($percentB !== null && $percentB <= 0) {
            $reasons[] = sprintf('Bollinger %%B %.2f — at/below lower band, potential rebound', $percentB);
            $ruleKeys[] = 'bb_lower_touch';
        }

        // Bollinger %B rejection: fires once, when %B drops back
        // under 1 after having been at/above it the previous day — a
        // rejection from the upper band, not merely "still up there."
        if ($percentB !== null && $previousPercentB !== null && $previousPercentB >= 1 && $percentB < 1) {
            $reasons[] = sprintf('Bollinger %%B fell back from %.2f to %.2f — rejected from upper band, potential pullback', $previousPercentB, $percentB);
            $ruleKeys[] = 'bb_upper_touch';
        }

        // Stochastic %K/%D crossover, only counted inside the
        // extreme zones — a cross in the 20-80 middle is noise. Like the
        // RSI/Bollinger bearish rules, a crossover fires once per event
        // rather than on every day the oscillator sits at an extreme.
        if ($today->stoch_k !== null && $today->stoch_d !== null
            && $yesterday?->stoch_k !== null && $yesterday?->stoch_d !== null) {
            $k = (float) $today->stoch_k;
            $d = (float) $today->stoch_d;
            $previousK = (float) $yesterday->stoch_k;
            $previousD = (float) $yesterday->stoch_d;

            if ($previousK <= $previousD && $k > $d && $d < 20) {
                $reasons[] = sprintf('Stochastic %%K crossed above %%D while oversold (%%K %.1f, %%D %.1f)', $k, $d);
                $ruleKeys[] = 'stoch_bull_cross';
            } elseif ($previousK >= $previousD && $k < $d && $d > 80) {
                $reasons[] = sprintf('Stochastic %%K crossed below %%D while overbought (%%K %.1f, %%D %.1f)', $k, $d);
                $ruleKeys[] = 'stoch_bear_cross';
            }
        }

        return [$reasons, $ruleKeys];
    }

    /**
     * Whether a dip-buy rule (RSI oversold, Bollinger lower band) is allowed to count as a buy vote today.
     *
     * Those rules fire on a LEVEL: every day the stock sits oversold. In a downtrend that is most days of the
     * slide, so the old behaviour recorded a "buy" on every one of them, long before any rebound (on USHL, 51 of
     * 64 buy days were below the 200-day average, and they lost on average). Now the trend decides how much
     * proof of a turn is needed first:
     *
     *   uptrend    a dip is a normal pullback: counts straight away
     *   sideways   needs 1 bounce confirmation
     *   downtrend  needs 2
     *
     * Bounce confirmations (each a different kind of evidence): the close is up on the day, RSI is turning up,
     * the MACD histogram is rising, volume is at or above its 20-day average.
     *
     * Not enough confirmations: the rule is still recorded (rule_keys and the reasons, so the rule scanner and the
     * feed show what happened) but does not vote, so the day stays hold, with a reason saying what it is waiting
     * for. Only dip rules are touched; sell logic is unchanged (it already needs a rollover and a long-term
     * downtrend).
     *
     * @param  list<string>  $ruleKeys
     * @return array{0: list<string>, 1: list<string>} the keys that may vote, and extra reasons to show
     */
    private function applyContext(array $ruleKeys, $today, $yesterday, $earlier, ?float $close, ?float $previousClose): array
    {
        if (array_intersect($ruleKeys, self::DIP_RULES) === []) {
            return [$ruleKeys, []];
        }

        $trend = $this->trend($today, $earlier, $close);
        $needed = self::CONFIRMATIONS_NEEDED[$trend];
        $confirmed = $this->bounceConfirmations($today, $yesterday, $close, $previousClose);
        $have = count($confirmed);

        if ($have >= $needed) {
            return [$ruleKeys, $needed === 0 ? [] : [sprintf('Dip-buy confirmed in a %s (%d of %d bounce signs: %s)', $trend, $have, $needed, implode(', ', $confirmed))]];
        }

        return [
            array_values(array_diff($ruleKeys, self::DIP_RULES)),
            [sprintf(
                'Oversold, but in a %s with no bounce yet — not a buy until it turns (%d of %d signs%s)',
                $trend,
                $have,
                $needed,
                $have > 0 ? ': '.implode(', ', $confirmed) : ''
            )],
        ];
    }

    /**
     * downtrend: below its 50-day AND 200-day averages with the 50-day falling. uptrend: above both with the 50-day
     * rising. Anything else is sideways. A stock too new for an SMA200 is judged on the SMA50 alone; one too new
     * for even that is "unknown".
     */
    private function trend($today, $earlier, ?float $close): string
    {
        if ($close === null || $today->sma_50 === null) {
            return 'unknown';
        }

        $sma50 = (float) $today->sma_50;
        $sma200 = $today->sma_200 !== null ? (float) $today->sma_200 : null;
        $then = $earlier?->sma_50 !== null ? (float) $earlier->sma_50 : null;

        if ($close < $sma50 && ($sma200 === null || $close < $sma200) && $then !== null && $sma50 < $then) {
            return 'downtrend';
        }

        if ($close > $sma50 && ($sma200 === null || $close > $sma200) && $then !== null && $sma50 > $then) {
            return 'uptrend';
        }

        return 'sideways';
    }

    /** @return list<string> the bounce signs present today */
    private function bounceConfirmations($today, $yesterday, ?float $close, ?float $previousClose): array
    {
        $signs = [];

        if ($close !== null && $previousClose !== null && $close > $previousClose) {
            $signs[] = 'close up';
        }
        if ($today->rsi_14 !== null && $yesterday?->rsi_14 !== null && (float) $today->rsi_14 > (float) $yesterday->rsi_14) {
            $signs[] = 'RSI turning up';
        }
        if ($today->macd_histogram !== null && $yesterday?->macd_histogram !== null && (float) $today->macd_histogram > (float) $yesterday->macd_histogram) {
            $signs[] = 'MACD histogram rising';
        }
        if ($today->volume_ratio !== null && (float) $today->volume_ratio >= 1.0) {
            $signs[] = 'volume above average';
        }

        return $signs;
    }

    /**
     * Computed from the close and that day's bands rather than read from
     * the stored bb_percent_b column, so the rule is always consistent
     * with the bands it's judged against (and works on rows recalculated
     * before that column existed). Same formula as
     * TechnicalAnalysisService::percentB().
     */
    private function percentB(?float $close, $indicator): ?float
    {
        if ($close === null || $indicator?->bb_upper === null || $indicator?->bb_lower === null) {
            return null;
        }

        $upper = (float) $indicator->bb_upper;
        $lower = (float) $indicator->bb_lower;

        return $upper > $lower ? ($close - $lower) / ($upper - $lower) : null;
    }

    /**
     * A small, bounded valuation nudge from EPS/P-E/PBV — capped at ±0.23
     * total (well under the 0.5 buy/sell threshold on its own), so it can
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
     * @return array{0: ?string, 1: ?string} reason, rule key
     */
    private function goldenDeathCross($today, array &$state): array
    {
        if ($today->sma_50 === null || $today->sma_200 === null || (float) $today->sma_200 === 0.0) {
            return [null, null];
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
            return [null, null];
        }

        $gapPct = abs($sma50 - $sma200) / $sma200 * 100;

        if ($gapPct < self::CROSS_MIN_GAP_PCT) {
            return [null, null];
        }

        $state['confirmed'] = true;

        return $side === 'above'
            ? [sprintf('Golden cross: SMA50 has held %.2f%% above SMA200 for %d+ days (long-term bullish)', $gapPct, self::CROSS_CONFIRM_DAYS), 'golden_cross']
            : [sprintf('Death cross: SMA50 has held %.2f%% below SMA200 for %d+ days (long-term bearish)', $gapPct, self::CROSS_CONFIRM_DAYS), 'death_cross'];
    }
}
