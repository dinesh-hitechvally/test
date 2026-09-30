<?php

namespace Tests\Unit\DataQuality;

use App\Models\DailyPrice;
use App\Models\DataQualityFlag;
use App\Models\Dividend;
use App\Models\Stock;
use App\Services\DataQuality\DataQualityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataQualityServiceTest extends TestCase
{
    use RefreshDatabase;

    private DataQualityService $quality;

    private Stock $stock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->quality = app(DataQualityService::class);
        $this->stock = Stock::create(['symbol' => 'TEST', 'company_name' => 'Test Co', 'is_active' => true]);
    }

    private function price(array $overrides = []): DailyPrice
    {
        return DailyPrice::create(array_merge([
            'stock_id' => $this->stock->id,
            'trade_date' => now()->toDateString(),
            'open_price' => 100, 'high_price' => 105, 'low_price' => 98, 'close_price' => 102,
            'volume' => 10000, 'turnover' => 1000000,
        ], $overrides));
    }

    public function test_valid_ohlc_raises_nothing(): void
    {
        $this->quality->checkOhlc($this->price());

        $this->assertSame(0, DataQualityFlag::count());
    }

    public function test_high_below_low_is_flagged_critical(): void
    {
        $this->quality->checkOhlc($this->price(['high_price' => 90, 'low_price' => 98]));

        $flag = DataQualityFlag::sole();
        $this->assertSame('invalid_ohlc', $flag->check_type);
        $this->assertSame('critical', $flag->severity);
        $this->assertSame($this->stock->id, $flag->stock_id);
    }

    public function test_close_outside_the_high_low_range_is_flagged(): void
    {
        $this->quality->checkOhlc($this->price(['close_price' => 150, 'high_price' => 105]));

        $this->assertSame('invalid_ohlc', DataQualityFlag::sole()->check_type);
    }

    public function test_a_zero_or_negative_price_is_flagged(): void
    {
        $this->quality->checkOhlc($this->price(['low_price' => 0]));

        $this->assertSame('invalid_ohlc', DataQualityFlag::sole()->check_type);
    }

    public function test_a_move_past_the_abnormal_threshold_is_flagged(): void
    {
        $previous = $this->price(['close_price' => 100]);
        $today = $this->price(['trade_date' => now()->addDay()->toDateString(), 'close_price' => 130]);

        $this->quality->checkAbnormalChange($today, $previous);

        $this->assertSame('abnormal_price_change', DataQualityFlag::sole()->check_type);
    }

    public function test_a_normal_move_is_not_flagged(): void
    {
        $previous = $this->price(['close_price' => 100]);
        $today = $this->price(['trade_date' => now()->addDay()->toDateString(), 'close_price' => 108]);

        $this->quality->checkAbnormalChange($today, $previous);

        $this->assertSame(0, DataQualityFlag::count());
    }

    public function test_zero_volume_with_a_price_move_is_flagged(): void
    {
        $previous = $this->price(['close_price' => 100]);
        $today = $this->price(['trade_date' => now()->addDay()->toDateString(), 'close_price' => 101, 'volume' => 0]);

        $this->quality->checkMissingVolume($today, $previous);

        $this->assertSame('missing_volume', DataQualityFlag::sole()->check_type);
    }

    public function test_zero_volume_with_no_price_move_is_not_flagged(): void
    {
        $previous = $this->price(['close_price' => 100]);
        $today = $this->price(['trade_date' => now()->addDay()->toDateString(), 'close_price' => 100, 'volume' => 0]);

        $this->quality->checkMissingVolume($today, $previous);

        $this->assertSame(0, DataQualityFlag::count());
    }

    public function test_overwriting_a_recent_row_is_not_flagged(): void
    {
        $recent = $this->price(['trade_date' => now()->subDays(2)->toDateString()]);

        $this->quality->checkOverwrite($recent);

        $this->assertSame(0, DataQualityFlag::count());
    }

    public function test_overwriting_an_already_settled_row_is_flagged(): void
    {
        $old = $this->price(['trade_date' => now()->subDays(20)->toDateString()]);

        $this->quality->checkOverwrite($old);

        $this->assertSame('corrected_historical_value', DataQualityFlag::sole()->check_type);
    }

    public function test_the_same_problem_is_not_flagged_twice_while_unresolved(): void
    {
        $price = $this->price(['high_price' => 90, 'low_price' => 98]);

        $this->quality->checkOhlc($price);
        $this->quality->checkOhlc($price);

        $this->assertSame(1, DataQualityFlag::count());
    }

    public function test_a_resolved_flag_can_be_raised_again(): void
    {
        $price = $this->price(['high_price' => 90, 'low_price' => 98]);
        $this->quality->checkOhlc($price);
        DataQualityFlag::sole()->update(['resolved_at' => now()]);

        $this->quality->checkOhlc($price);

        $this->assertSame(2, DataQualityFlag::count());
    }

    public function test_a_stock_missing_a_date_most_others_have_is_flagged(): void
    {
        // 19 other active stocks covered + this one missing = 95% coverage, past the 90% threshold.
        $covered = now()->subDays(3)->toDateString();
        for ($i = 0; $i < 19; $i++) {
            $this->price(['stock_id' => Stock::create(['symbol' => "S{$i}", 'is_active' => true])->id, 'trade_date' => $covered]);
        }
        // $this->stock never got a row for $covered.

        $flagged = $this->quality->scanMissingTradingDates();

        $this->assertSame(1, $flagged);
        $this->assertTrue(DataQualityFlag::where('stock_id', $this->stock->id)->where('trade_date', $covered)
            ->where('check_type', 'missing_trading_date')->exists());
    }

    public function test_a_large_drop_near_a_known_dividend_date_is_flagged_as_info(): void
    {
        Dividend::create([
            'stock_id' => $this->stock->id, 'fiscal_year' => '2081/2082',
            'bonus_share_pct' => 10, 'announcement_date' => now()->subDays(60)->toDateString(),
        ]);
        $this->price(['trade_date' => now()->subDays(61)->toDateString(), 'close_price' => 100]);
        $this->price(['trade_date' => now()->subDays(60)->toDateString(), 'close_price' => 90]); // -10%, near the dividend date

        $flagged = $this->quality->scanUnadjustedCorporateActions();

        $this->assertSame(1, $flagged);
        $this->assertSame('info', DataQualityFlag::sole()->severity);
    }

    public function test_a_similar_drop_with_no_nearby_corporate_action_is_not_flagged(): void
    {
        $this->price(['trade_date' => now()->subDays(61)->toDateString(), 'close_price' => 100]);
        $this->price(['trade_date' => now()->subDays(60)->toDateString(), 'close_price' => 90]);

        $this->assertSame(0, $this->quality->scanUnadjustedCorporateActions());
    }
}
