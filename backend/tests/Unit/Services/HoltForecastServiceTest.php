<?php

namespace Tests\Unit\Services;

use App\Services\MarketData\HoltForecastService;
use PHPUnit\Framework\TestCase;

class HoltForecastServiceTest extends TestCase
{
    private HoltForecastService $holt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->holt = new HoltForecastService();
    }

    public function test_a_perfectly_linear_series_forecasts_exactly_along_that_line(): void
    {
        $closes = [10.0, 20.0, 30.0, 40.0, 50.0];

        $forecast = $this->holt->forecast($closes, 3);

        $this->assertEqualsWithDelta(60.0, $forecast[0], 0.01);
        $this->assertEqualsWithDelta(70.0, $forecast[1], 0.01);
        $this->assertEqualsWithDelta(80.0, $forecast[2], 0.01);
    }

    public function test_a_flat_series_forecasts_flat(): void
    {
        $closes = array_fill(0, 10, 50.0);

        $forecast = $this->holt->forecast($closes, 5);

        foreach ($forecast as $value) {
            $this->assertEqualsWithDelta(50.0, $value, 0.01);
        }
    }

    public function test_a_noisy_uptrend_forecasts_upward(): void
    {
        // Trending up with small noise superimposed.
        $closes = [];
        foreach (range(0, 39) as $i) {
            $closes[] = 100 + ($i * 1.5) + sin($i) * 2;
        }

        $forecast = $this->holt->forecast($closes, 10);

        $this->assertGreaterThan(end($closes), $forecast[0]);
        $this->assertGreaterThan($forecast[0], $forecast[9]); // still rising further out
    }

    public function test_a_noisy_downtrend_forecasts_downward(): void
    {
        $closes = [];
        foreach (range(0, 39) as $i) {
            $closes[] = 200 - ($i * 1.5) + sin($i) * 2;
        }

        $forecast = $this->holt->forecast($closes, 10);

        $this->assertLessThan(end($closes), $forecast[0]);
        $this->assertLessThan($forecast[0], $forecast[9]);
    }

    public function test_forecast_never_goes_negative(): void
    {
        // Steep downtrend that would cross zero if extrapolated far enough.
        $closes = [50.0, 40.0, 30.0, 20.0, 10.0];

        $forecast = $this->holt->forecast($closes, 10);

        foreach ($forecast as $value) {
            $this->assertGreaterThanOrEqual(0.0, $value);
        }
    }

    public function test_backtest_returns_one_result_per_walk_forward_step(): void
    {
        $closes = [];
        foreach (range(0, 99) as $i) {
            $closes[] = 100 + ($i * 0.5) + sin($i / 3) * 3;
        }

        $results = $this->holt->backtestOne($closes, horizon: 5, step: 5);

        $this->assertNotEmpty($results);

        foreach ($results as $result) {
            $this->assertArrayHasKey('pct_error', $result);
            $this->assertArrayHasKey('correct_direction', $result);
            $this->assertGreaterThanOrEqual(0.0, $result['pct_error']);
            $this->assertIsBool($result['correct_direction']);
        }
    }
}
