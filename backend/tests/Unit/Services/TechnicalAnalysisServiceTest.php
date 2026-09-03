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
}
