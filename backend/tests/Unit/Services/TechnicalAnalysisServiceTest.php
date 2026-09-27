<?php

namespace Tests\Unit\Services;

use App\Services\MarketData\TechnicalAnalysisService;
use PHPUnit\Framework\TestCase;

class TechnicalAnalysisServiceTest extends TestCase
{
    private TechnicalAnalysisService $ta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ta = new TechnicalAnalysisService();
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
}
