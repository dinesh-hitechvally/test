<?php

namespace Tests\Feature\Analysis;

use App\Models\DailyPrice;
use App\Models\Signal;
use App\Models\SignalAccuracyStat;
use App\Models\SignalBreakdown;
use App\Models\Stock;
use App\Services\Analysis\Signals\SignalAccuracyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignalAccuracyServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A stock whose price rises 1 a day from 100 (so every day's 5-day-forward return is positive), with a signal
     * on each of the first $days days carrying the given buy % / sell %.
     *
     * @param  list<array{0: string, 1: float, 2: float}>  $days  [signal, buy %, sell %] per day, in order
     */
    private function stockWithSignals(array $days, int $totalDays = 40): Stock
    {
        $stock = Stock::create(['symbol' => 'TEST', 'company_name' => 'Test Co', 'is_active' => true]);

        for ($i = 0; $i < $totalDays; $i++) {
            $date = now()->setDate(2024, 1, 1)->addDays($i)->toDateString();
            DailyPrice::create(['stock_id' => $stock->id, 'trade_date' => $date, 'open_price' => 100 + $i, 'high_price' => 100 + $i, 'low_price' => 100 + $i, 'close_price' => 100 + $i, 'volume' => 1000]);

            if (isset($days[$i])) {
                [$decision, $buy, $sell] = $days[$i];
                $signal = Signal::create(['stock_id' => $stock->id, 'trade_date' => $date, 'signal' => $decision, 'score' => ($buy - $sell) / 100, 'reasons' => [], 'rule_keys' => []]);
                SignalBreakdown::create(['stock_id' => $stock->id, 'trade_date' => $date, 'buy_pct' => $buy, 'sell_pct' => $sell, 'hold_pct' => 100 - $buy - $sell, 'hold_score_long_term' => 0, 'hold_score_consolidation' => 0, 'hold_score_wait_confirmation' => 0, 'hold_score_profit_protection' => 0, 'hold_score_temporary_weakness' => 0, 'hold_score_overbought' => 0, 'conditions' => []]);
            }
        }

        return $stock;
    }

    public function test_it_groups_days_by_confidence_band_using_the_stored_percentages(): void
    {
        $this->stockWithSignals([
            ['buy', 62, 10],   // 60-70 buy band
            ['buy', 65, 10],   // 60-70 buy band
            ['hold', 45, 20],  // 40-50 buy band (under the decision line), 20-30 sell: not banded
            ['sell', 10, 55],  // 50-60 sell band
        ]);

        app(SignalAccuracyService::class)->backtest(5);

        $band = fn (string $type, string $band) => SignalAccuracyStat::where('signal_type', $type)->where('confidence_band', $band)->first();

        $this->assertSame(2, $band('buy', '60-70')->sample_size);
        $this->assertEquals(100.0, (float) $band('buy', '60-70')->win_rate);   // prices only rise
        $this->assertSame(1, $band('buy', '40-50')->sample_size);              // a HOLD day just under the line is still measured
        $this->assertSame(1, $band('sell', '50-60')->sample_size);
        $this->assertEquals(0.0, (float) $band('sell', '50-60')->win_rate);    // a sell is wrong when price rises
        $this->assertNull($band('buy', '70+'));                                // no day fell in that band
    }

    public function test_the_all_signals_rows_have_no_band_and_stay_what_the_report_and_ai_read(): void
    {
        $this->stockWithSignals([['buy', 62, 10], ['buy', 70, 5], ['sell', 10, 60]]);

        app(SignalAccuracyService::class)->backtest(5);

        $buy = app(SignalAccuracyService::class)->latestFor('buy');

        $this->assertNull($buy->confidence_band);
        $this->assertSame(2, $buy->sample_size);
        $this->assertSame(['buy', 'sell'], SignalAccuracyStat::whereNull('confidence_band')->orderBy('signal_type')->pluck('signal_type')->all());
    }

    public function test_days_too_close_to_the_end_to_have_an_outcome_are_left_out(): void
    {
        // Signal on the very last day: there are no 5 later days to judge it.
        $days = array_fill(0, 39, null);
        $days[39] = ['buy', 80, 5];
        $this->stockWithSignals($days);

        app(SignalAccuracyService::class)->backtest(5);

        $this->assertNull(SignalAccuracyStat::where('signal_type', 'buy')->where('confidence_band', '70+')->first());
    }

    public function test_the_report_includes_the_band_rows(): void
    {
        $this->stockWithSignals([['buy', 62, 10]]);
        app(SignalAccuracyService::class)->backtest(5);

        $report = app(SignalAccuracyService::class)->latestReport();

        $this->assertTrue($report['available']);
        $this->assertContains('60-70', $report['stats']->pluck('confidence_band')->all());
    }
}
