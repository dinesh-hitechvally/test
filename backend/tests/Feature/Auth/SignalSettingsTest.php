<?php

namespace Tests\Feature\Auth;

use App\Models\Signal;
use App\Models\SignalBreakdown;
use App\Models\Stock;
use App\Models\TechnicalIndicator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** A person's own weights / minimum / margin / guard, and the Signals page deciding each stock again under them. */
class SignalSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const SAVE = 'mutation($w: SignalWeightsInput!, $min: Int!, $margin: Int!, $guard: Boolean!) {
        updateSignalSettings(weights: $w, min_pct: $min, margin: $margin, guard_extremes: $guard) { is_custom min_pct margin guard_extremes weights { category weight } }
    }';

    private function login(string $email = 't@example.com'): User
    {
        return tap(User::create(['name' => 'T', 'email' => $email, 'password' => 'password']), fn ($u) => Sanctum::actingAs($u));
    }

    private function weights(array $override = []): array
    {
        return $override + ['technical' => 25, 'fundamental' => 25, 'trend' => 15, 'momentum' => 10, 'volume' => 10, 'risk' => 10, 'valuation' => 5];
    }

    private function save(array $weights, int $min = 50, int $margin = 0, bool $guard = true)
    {
        return $this->graphQL(self::SAVE, ['w' => $weights, 'min' => $min, 'margin' => $margin, 'guard' => $guard]);
    }

    public function test_without_settings_the_system_defaults_apply(): void
    {
        $this->login();

        $s = $this->graphQL('{ signalSettings { is_custom min_pct margin guard_extremes weights { category weight } defaults { min_pct } } }')
            ->assertJsonMissingPath('errors')->json('data.signalSettings');

        $this->assertFalse($s['is_custom']);
        $this->assertEquals(50, $s['min_pct']);
        $this->assertEquals(0, $s['margin']);
        $this->assertTrue($s['guard_extremes']);
        $this->assertSame(['technical', 'fundamental', 'trend', 'momentum', 'volume', 'risk', 'valuation'], array_column($s['weights'], 'category'));
        $this->assertEquals(25, $s['weights'][0]['weight']);
    }

    public function test_saved_settings_are_returned_and_reset_returns_to_the_defaults(): void
    {
        $user = $this->login();

        $saved = $this->save($this->weights(['technical' => 40, 'fundamental' => 10]), min: 60, margin: 15, guard: false)
            ->assertJsonMissingPath('errors')->json('data.updateSignalSettings');

        $this->assertTrue($saved['is_custom']);
        $this->assertEquals(60, $saved['min_pct']);
        $this->assertEquals(40, $saved['weights'][0]['weight']);
        $this->assertFalse($saved['guard_extremes']);
        $this->assertDatabaseHas('signal_settings', ['user_id' => $user->id, 'weight_technical' => 40, 'min_pct' => 60, 'margin' => 15, 'guard_extremes' => false]);

        $reset = $this->graphQL('mutation { resetSignalSettings { is_custom min_pct guard_extremes } }')->json('data.resetSignalSettings');

        $this->assertFalse($reset['is_custom']);
        $this->assertEquals(50, $reset['min_pct']);
        $this->assertDatabaseMissing('signal_settings', ['user_id' => $user->id]);
    }

    public function test_one_persons_settings_do_not_change_anothers(): void
    {
        $this->login('a@example.com');
        $this->save($this->weights(['technical' => 40, 'fundamental' => 10]), min: 70);

        $this->login('b@example.com');
        $this->assertFalse($this->graphQL('{ signalSettings { is_custom } }')->json('data.signalSettings.is_custom'));
    }

    public function test_the_weights_must_add_up_to_100(): void
    {
        $this->login();

        $r = $this->save($this->weights(['technical' => 30]));

        $this->assertSame('validation', $r->json('errors.0.extensions.category') ?? 'validation');
        $this->assertStringContainsString('add up to 100', json_encode($r->json('errors')));
        $this->assertDatabaseCount('signal_settings', 0);
    }

    public function test_out_of_range_values_are_refused(): void
    {
        $this->login();

        foreach ([[39, 0], [91, 0], [50, 51], [50, -1]] as [$min, $margin]) {
            $this->assertNotNull($this->save($this->weights(), $min, $margin)->json('errors'), "min {$min} margin {$margin}");
        }
        $this->assertNotNull($this->save($this->weights(['technical' => 101, 'fundamental' => -76]))->json('errors'));
        $this->assertDatabaseCount('signal_settings', 0);
    }

    public function test_settings_need_a_login(): void
    {
        $this->assertSame(401, $this->graphQLStatus($this->graphQL('{ signalSettings { min_pct } }')));
    }

    // ---------------------------------------------------------------- the Signals page under the settings

    /** A stock whose stored (system) decision is BUY 56 / SELL 18: technical strongly buy, fundamental strongly sell. */
    private function stock(string $symbol, float $rsi = 50): Stock
    {
        $stock = Stock::create(['symbol' => $symbol, 'company_name' => $symbol, 'is_active' => true]);
        Signal::create(['stock_id' => $stock->id, 'trade_date' => '2024-01-03', 'signal' => 'buy', 'score' => 0.38, 'reasons' => ['stored'], 'rule_keys' => []]);

        $cats = [];
        foreach (['technical' => [90, 5, 5], 'fundamental' => [10, 80, 10]] as $c => [$b, $s, $h]) {
            $cats["{$c}_buy"] = $b;
            $cats["{$c}_sell"] = $s;
            $cats["{$c}_hold"] = $h;
        }
        SignalBreakdown::create($cats + [
            'stock_id' => $stock->id, 'trade_date' => '2024-01-03', 'buy_pct' => 50, 'sell_pct' => 42.5, 'hold_pct' => 7.5, 'hold_type' => null,
            'hold_score_long_term' => 0, 'hold_score_consolidation' => 0, 'hold_score_wait_confirmation' => 0, 'hold_score_profit_protection' => 0,
            'hold_score_temporary_weakness' => 0, 'hold_score_overbought' => 0, 'conditions' => [],
        ]);
        TechnicalIndicator::create(['stock_id' => $stock->id, 'trade_date' => '2024-01-03', 'rsi_14' => $rsi]);

        return $stock;
    }

    private function board(): array
    {
        return collect($this->graphQL('{ signalBoard { symbol signal score buy_pct sell_pct hold_type reasons } }')
            ->assertJsonMissingPath('errors')->json('data.signalBoard'))->keyBy('symbol')->all();
    }

    public function test_the_board_is_unchanged_for_a_person_with_no_settings(): void
    {
        $this->stock('AAA');
        $this->login();

        $row = $this->board()['AAA'];

        $this->assertSame('buy', $row['signal']);
        $this->assertSame(['stored'], $row['reasons']);
        $this->assertEquals(50, $row['buy_pct']);
    }

    public function test_the_board_is_decided_again_under_a_persons_weights(): void
    {
        $this->stock('AAA');
        $this->login();

        // Fundamentals count for everything: the stored BUY becomes a SELL for this person.
        $this->save(['technical' => 0, 'fundamental' => 100, 'trend' => 0, 'momentum' => 0, 'volume' => 0, 'risk' => 0, 'valuation' => 0])->assertJsonMissingPath('errors');
        $row = $this->board()['AAA'];

        $this->assertSame('sell', $row['signal']);
        $this->assertEquals(80, $row['sell_pct']);
        $this->assertEquals(-0.7, $row['score']);
        $this->assertStringContainsString('Your settings', $row['reasons'][0]);
    }

    public function test_the_board_applies_the_persons_minimum_and_margin(): void
    {
        $this->stock('AAA'); // technical + fundamental only, 25:25 → buy 50, sell 42.5
        $this->login();

        $this->save($this->weights(), min: 60);
        $this->assertSame('hold', $this->board()['AAA']['signal']); // 50 is under their 60

        $this->save($this->weights(), min: 50, margin: 10);
        $this->assertSame('hold', $this->board()['AAA']['signal']); // leads by 7.5, they want 10

        $this->save($this->weights(), min: 40, margin: 5);
        $this->assertSame('buy', $this->board()['AAA']['signal']);
    }

    public function test_the_guard_holds_a_sell_at_an_oversold_price_only_when_switched_on(): void
    {
        $this->stock('LOW', rsi: 22);
        $this->login();
        $fundamentalsOnly = ['technical' => 0, 'fundamental' => 100, 'trend' => 0, 'momentum' => 0, 'volume' => 0, 'risk' => 0, 'valuation' => 0];

        $this->save($fundamentalsOnly, guard: true);
        $guarded = $this->board()['LOW'];
        $this->assertSame('hold', $guarded['signal']);
        $this->assertSame('wait_confirmation', $guarded['hold_type']);
        $this->assertStringContainsString('selling the low', json_encode($guarded['reasons']));

        $this->save($fundamentalsOnly, guard: false);
        $this->assertSame('sell', $this->board()['LOW']['signal']);
    }

    public function test_another_persons_board_still_shows_the_stored_decision(): void
    {
        $this->stock('AAA');
        $this->login('a@example.com');
        $this->save(['technical' => 0, 'fundamental' => 100, 'trend' => 0, 'momentum' => 0, 'volume' => 0, 'risk' => 0, 'valuation' => 0]);

        $this->login('b@example.com');

        $this->assertSame('buy', $this->board()['AAA']['signal']);
    }
}
