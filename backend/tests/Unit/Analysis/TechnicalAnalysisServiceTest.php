<?php

namespace Tests\Unit\Analysis;

use App\Services\Analysis\Indicators\TechnicalAnalysisService;
use PHPUnit\Framework\TestCase;

class TechnicalAnalysisServiceTest extends TestCase
{
    private TechnicalAnalysisService $ta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ta = new TechnicalAnalysisService;
    }

    public function test_sma_is_null_before_enough_data_then_the_correct_average(): void
    {
        $values = [1, 2, 3, 4, 5, 6];
        $result = $this->ta->sma($values, 3);

        $this->assertNull($result[0]);
        $this->assertNull($result[1]);
        $this->assertEqualsWithDelta(2.0, $result[2], 0.0001); // (1+2+3)/3
        $this->assertEqualsWithDelta(3.0, $result[3], 0.0001); // (2+3+4)/3
        $this->assertEqualsWithDelta(5.0, $result[5], 0.0001); // (4+5+6)/3
    }

    public function test_ema_seeds_with_sma_then_applies_smoothing(): void
    {
        $values = [10, 11, 12, 13, 14, 15];
        $period = 3;
        $result = $this->ta->ema($values, $period);

        $this->assertNull($result[0]);
        $this->assertNull($result[1]);
        $this->assertEqualsWithDelta(11.0, $result[2], 0.0001); // seed = SMA(10,11,12)

        $multiplier = 2 / ($period + 1);
        $expected = (($values[3] - 11.0) * $multiplier) + 11.0;
        $this->assertEqualsWithDelta($expected, $result[3], 0.0001);
    }

    public function test_rsi_is_100_when_every_change_is_a_gain(): void
    {
        $values = range(1, 20); // strictly increasing: no losses at all
        $result = $this->ta->rsi($values, 14);

        $this->assertEqualsWithDelta(100.0, $result[14], 0.0001);
        $this->assertEqualsWithDelta(100.0, $result[19], 0.0001);
    }

    public function test_rsi_is_zero_when_every_change_is_a_loss(): void
    {
        $values = range(20, 1); // strictly decreasing: no gains at all
        $result = $this->ta->rsi($values, 14);

        $this->assertEqualsWithDelta(0.0, $result[14], 0.0001);
    }

    public function test_macd_line_equals_fast_ema_minus_slow_ema(): void
    {
        $values = array_map(fn ($i) => 100 + sin($i / 3) * 10, range(0, 60));
        $macd = $this->ta->macd($values, 12, 26, 9);
        $emaFast = $this->ta->ema($values, 12);
        $emaSlow = $this->ta->ema($values, 26);

        for ($i = 26; $i < count($values); $i++) {
            $this->assertEqualsWithDelta($emaFast[$i] - $emaSlow[$i], $macd['macd'][$i], 0.0001);
        }
    }

    public function test_bollinger_bands_bracket_the_middle_sma(): void
    {
        $values = array_map(fn ($i) => 100 + sin($i / 3) * 10, range(0, 40));
        $bb = $this->ta->bollingerBands($values, 20, 2.0);
        $sma = $this->ta->sma($values, 20);

        for ($i = 19; $i < count($values); $i++) {
            $this->assertEqualsWithDelta($sma[$i], $bb['middle'][$i], 0.0001);
            $this->assertGreaterThan($bb['middle'][$i], $bb['upper'][$i]);
            $this->assertLessThan($bb['middle'][$i], $bb['lower'][$i]);
        }
    }

    public function test_atr_is_null_before_enough_data_then_positive(): void
    {
        $closes = array_map(fn ($i) => 100 + sin($i / 3) * 10, range(0, 30));
        $highs = array_map(fn ($c) => $c + 2, $closes);
        $lows = array_map(fn ($c) => $c - 2, $closes);

        $atr = $this->ta->atr($highs, $lows, $closes, 14);

        $this->assertNull($atr[13]);
        $this->assertGreaterThan(0, $atr[14]);
        $this->assertGreaterThan(0, $atr[29]);
    }

    public function test_percent_b_is_0_on_the_lower_band_1_on_the_upper_and_null_when_flat(): void
    {
        $bands = ['upper' => [null, 110, 110, 100], 'lower' => [null, 90, 90, 100]];

        $result = $this->ta->percentB([100, 90, 110, 100], $bands);

        $this->assertNull($result[0]); // bands not available yet
        $this->assertEqualsWithDelta(0.0, $result[1], 0.0001);
        $this->assertEqualsWithDelta(1.0, $result[2], 0.0001);
        $this->assertNull($result[3]); // zero-width bands
    }

    public function test_stochastic_warms_up_then_reads_100_in_a_steady_uptrend(): void
    {
        // Every close is the new 14-day high, so raw %K is 100 every day.
        $closes = range(1, 25);

        $stoch = $this->ta->stochastic($closes, $closes, $closes, 14, 3, 3);

        $this->assertNull($stoch['k'][14]); // raw %K starts at 13, smoothed %K needs 3 of them
        $this->assertEqualsWithDelta(100.0, $stoch['k'][15], 0.0001);
        $this->assertNull($stoch['d'][16]);
        $this->assertEqualsWithDelta(100.0, $stoch['d'][17], 0.0001);
        $this->assertEqualsWithDelta(100.0, $stoch['d'][24], 0.0001);
    }

    public function test_stochastic_matches_a_hand_calculation(): void
    {
        $highs = [10, 12, 11, 13, 12];
        $lows = [8, 9, 9, 10, 10];
        $closes = [9, 11, 10, 12, 11];

        // period 3, no extra smoothing (1), %D over 2:
        //   i=2: (10-8)/(12-8)  = 50.0
        //   i=3: (12-9)/(13-9)  = 75.0
        //   i=4: (11-9)/(13-9)  = 50.0
        $stoch = $this->ta->stochastic($highs, $lows, $closes, 3, 1, 2);

        $this->assertNull($stoch['k'][1]);
        $this->assertEqualsWithDelta(50.0, $stoch['k'][2], 0.0001);
        $this->assertEqualsWithDelta(75.0, $stoch['k'][3], 0.0001);
        $this->assertEqualsWithDelta(50.0, $stoch['k'][4], 0.0001);
        $this->assertNull($stoch['d'][2]);
        $this->assertEqualsWithDelta(62.5, $stoch['d'][3], 0.0001);
        $this->assertEqualsWithDelta(62.5, $stoch['d'][4], 0.0001);
    }

    public function test_stochastic_reads_neutral_50_when_the_range_is_flat(): void
    {
        $flat = array_fill(0, 20, 100.0);

        $stoch = $this->ta->stochastic($flat, $flat, $flat);

        $this->assertEqualsWithDelta(50.0, $stoch['k'][19], 0.0001);
        $this->assertEqualsWithDelta(50.0, $stoch['d'][19], 0.0001);
    }

    public function test_fifty_two_week_range_drops_out_a_high_once_its_past_365_days(): void
    {
        $start = new \DateTimeImmutable('2025-01-01');
        $dates = [];
        $highs = [];
        $lows = [];

        // Day 0: a spike high of 500. Every day after: a flat 100-110 range.
        for ($i = 0; $i < 400; $i++) {
            $dates[] = $start->modify("+{$i} days")->format('Y-m-d');
            $highs[] = $i === 0 ? 500.0 : 110.0;
            $lows[] = $i === 0 ? 50.0 : 100.0;
        }

        $range = $this->ta->fiftyTwoWeekRange($dates, $highs, $lows);

        // Within 365 days of the spike: still sees it.
        $this->assertEqualsWithDelta(500.0, $range['high'][300], 0.0001);
        $this->assertEqualsWithDelta(50.0, $range['low'][300], 0.0001);
        // Past 365 days: the spike has rolled out of the window.
        $this->assertEqualsWithDelta(110.0, $range['high'][399], 0.0001);
        $this->assertEqualsWithDelta(100.0, $range['low'][399], 0.0001);
    }

    public function test_volume_ratio_is_null_before_enough_data_then_the_correct_ratio(): void
    {
        $volumes = [...array_fill(0, 19, 1000), 3000]; // 19 days at 1000, then a 3000 day

        $result = $this->ta->volumeRatio($volumes, 20);

        $this->assertNull($result[18]); // only 19 days of history so far
        // avg of 19×1000 + 3000 over 20 = 1100; ratio = 3000/1100
        $this->assertEqualsWithDelta(3000 / 1100, $result[19], 0.0001);
    }

    public function test_support_resistance_levels_find_the_closest_level_each_side(): void
    {
        // A clean V: down to a 90 low (3 touches, the strongest support),
        // then up past a 120 high (resistance), ending at 100.
        $highs = [100, 105, 95, 100, 115, 125, 118, 110, 102, 100];
        $lows = [95, 98, 90, 92, 108, 118, 112, 90, 96, 94];
        // Re-touch 90 so it clusters as the strongest support.
        $lows[7] = 90.0;

        $levels = $this->ta->supportResistanceLevels($highs, $lows, currentPrice: 100.0, window: 2);

        $this->assertNotEmpty($levels['support']);
        $this->assertNotEmpty($levels['resistance']);
        foreach ($levels['support'] as $level) {
            $this->assertLessThan(100.0, $level['price']);
        }
        foreach ($levels['resistance'] as $level) {
            $this->assertGreaterThan(100.0, $level['price']);
        }
    }

    public function test_atr_percent_is_atr_divided_by_close(): void
    {
        $closes = array_map(fn ($i) => 100 + sin($i / 3) * 10, range(0, 30));
        $highs = array_map(fn ($c) => $c + 2, $closes);
        $lows = array_map(fn ($c) => $c - 2, $closes);
        $atr = $this->ta->atr($highs, $lows, $closes, 14);

        $result = $this->ta->atrPercent($atr, $closes);

        $this->assertNull($result[13]); // atr itself isn't available yet
        $this->assertEqualsWithDelta($atr[14] / $closes[14] * 100, $result[14], 0.0001);
        $this->assertEqualsWithDelta($atr[29] / $closes[29] * 100, $result[29], 0.0001);
    }

    public function test_adx_is_null_before_a_full_two_periods_of_data(): void
    {
        $closes = array_map(fn ($i) => 100 + sin($i / 3) * 10, range(0, 40));
        $highs = array_map(fn ($c) => $c + 2, $closes);
        $lows = array_map(fn ($c) => $c - 2, $closes);

        $adx = $this->ta->adx($highs, $lows, $closes, 14);

        // First possible ADX value is at index (14×2)-1 = 27.
        $this->assertNull($adx['adx'][26]);
        $this->assertNotNull($adx['adx'][27]);
    }

    public function test_adx_di_plus_dominates_in_a_steady_uptrend(): void
    {
        // Every day a new high and a new low, steadily rising — pure
        // directional movement, all of it up.
        $highs = array_map(fn ($i) => 100 + $i, range(0, 40));
        $lows = array_map(fn ($i) => 95 + $i, range(0, 40));
        $closes = array_map(fn ($i) => 97 + $i, range(0, 40));

        $adx = $this->ta->adx($highs, $lows, $closes, 14);

        $this->assertGreaterThan($adx['minus_di'][30], $adx['plus_di'][30]);
        $this->assertGreaterThan(0, $adx['plus_di'][30]);
        $this->assertEqualsWithDelta(0.0, $adx['minus_di'][30], 0.01); // no down days at all
        // A clean, unbroken trend should read as strongly trending.
        $this->assertGreaterThan(50, $adx['adx'][40]);
        $this->assertLessThanOrEqual(100, $adx['adx'][40]);
    }

    public function test_adx_di_minus_dominates_in_a_steady_downtrend(): void
    {
        $highs = array_map(fn ($i) => 140 - $i, range(0, 40));
        $lows = array_map(fn ($i) => 135 - $i, range(0, 40));
        $closes = array_map(fn ($i) => 137 - $i, range(0, 40));

        $adx = $this->ta->adx($highs, $lows, $closes, 14);

        $this->assertGreaterThan($adx['plus_di'][30], $adx['minus_di'][30]);
        $this->assertEqualsWithDelta(0.0, $adx['plus_di'][30], 0.01); // no up days at all
        $this->assertGreaterThan(50, $adx['adx'][40]);
    }

    public function test_adx_reads_near_zero_when_price_never_moves(): void
    {
        $flat = array_fill(0, 40, 100.0);

        $adx = $this->ta->adx($flat, $flat, $flat, 14);

        $this->assertEqualsWithDelta(0.0, $adx['adx'][39], 0.0001);
    }

    public function test_support_resistance_series_at_the_last_day_matches_a_direct_point_in_time_read(): void
    {
        $highs = [100, 105, 95, 100, 115, 125, 118, 110, 102, 100];
        $lows = [95, 98, 90, 92, 108, 118, 112, 90, 96, 94];
        $closes = [97, 101, 92, 96, 111, 120, 115, 100, 99, 100];

        $series = $this->ta->supportResistanceSeries($highs, $lows, $closes, lookback: 260, window: 2);
        $direct = $this->ta->supportResistanceLevels($highs, $lows, currentPrice: $closes[9], window: 2);

        $this->assertSame($direct['support'][0]['price'] ?? null, $series['support'][9]);
        $this->assertSame($direct['resistance'][0]['price'] ?? null, $series['resistance'][9]);
    }
}
