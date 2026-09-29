<?php

namespace Tests\Feature\Watchlists;

use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WatchlistEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private const SET_ALERT = 'mutation ($w: Int!, $s: Int!) { setWatchlistAlert(watchlist_id: $w, stock_id: $s, alert_price: 500, alert_direction: "above") }';

    private Stock $stock;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::create(['name' => 'T', 'email' => 't@example.com', 'password' => 'password']));
        $this->stock = Stock::create(['symbol' => 'TEST', 'company_name' => 'Test Co', 'is_active' => true]);
    }

    public function test_create_add_alert_and_remove(): void
    {
        $id = $this->createWatchlist();
        $vars = ['w' => $id, 's' => $this->stock->id];

        $this->graphQL('mutation ($w: Int!, $s: Int) { addWatchlistStock(watchlist_id: $w, stock_id: $s) { stocks { symbol } } }', $vars)
            ->assertJsonPath('data.addWatchlistStock.stocks.0.symbol', 'TEST');

        $this->graphQL(self::SET_ALERT, $vars)->assertJsonPath('data.setWatchlistAlert', 'Alert saved.');

        $this->graphQL('{ watchlists { stocks { change_pct pivot { alert_direction } } } }')
            ->assertJsonPath('data.watchlists.0.stocks.0.pivot.alert_direction', 'above')
            ->assertJsonPath('data.watchlists.0.stocks.0.change_pct', null);

        $this->graphQL('mutation ($w: Int!, $s: Int!) { removeWatchlistStock(watchlist_id: $w, stock_id: $s) }', $vars)
            ->assertJsonMissingPath('errors');
        $this->graphQL('{ watchlists { stocks { id } } }')->assertJsonCount(0, 'data.watchlists.0.stocks');
    }

    public function test_alert_on_a_stock_not_in_the_watchlist_is_404(): void
    {
        $response = $this->graphQL(self::SET_ALERT, ['w' => $this->createWatchlist(), 's' => $this->stock->id])
            ->assertGraphQLErrorMessage('That stock is not on this watchlist.');

        $this->assertSame(404, $this->graphQLStatus($response));
    }

    public function test_another_users_watchlist_is_404(): void
    {
        $theirs = User::create(['name' => 'O', 'email' => 'o@example.com', 'password' => 'password'])
            ->watchlists()->create(['name' => 'Theirs']);

        $response = $this->graphQL('mutation ($w: Int!, $s: Int) { addWatchlistStock(watchlist_id: $w, stock_id: $s) { id } }',
            ['w' => $theirs->id, 's' => $this->stock->id]);

        $this->assertSame(404, $this->graphQLStatus($response));
    }

    public function test_saved_screens_can_be_created_listed_and_deleted(): void
    {
        $id = $this->graphQL('mutation { createSavedScreen(name: "Oversold", filters: {signal: "buy", rsi_max: 30}) { id filters } }')
            ->assertJsonPath('data.createSavedScreen.filters', ['signal' => 'buy', 'rsi_max' => 30])
            ->json('data.createSavedScreen.id');

        $this->graphQL('{ savedScreens { name filters } }')->assertJsonPath('data.savedScreens.0.filters.signal', 'buy');

        $this->graphQL('mutation ($id: Int!) { deleteSavedScreen(id: $id) }', ['id' => $id])->assertJsonMissingPath('errors');
        $this->graphQL('{ savedScreens { id } }')->assertJsonCount(0, 'data.savedScreens');
    }

    private function createWatchlist(): int
    {
        return $this->graphQL('mutation { createWatchlist(name: "Banks") { id } }')->json('data.createWatchlist.id');
    }
}
