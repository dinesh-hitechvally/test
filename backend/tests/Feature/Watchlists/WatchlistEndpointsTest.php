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

    private Stock $stock;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::create(['name' => 'T', 'email' => 't@example.com', 'password' => 'password']));
        $this->stock = Stock::create(['symbol' => 'TEST', 'company_name' => 'Test Co', 'is_active' => true]);
    }

    public function test_create_add_alert_and_remove(): void
    {
        $id = $this->postJson('/api/watchlists', ['name' => 'Banks'])->assertCreated()->json('id');

        $this->postJson("/api/watchlists/{$id}/items", ['stock_id' => $this->stock->id])
            ->assertOk()
            ->assertJsonPath('stocks.0.symbol', 'TEST');

        $this->putJson("/api/watchlists/{$id}/items/{$this->stock->id}/alert", ['alert_price' => 500, 'alert_direction' => 'above'])
            ->assertOk()
            ->assertJsonPath('message', 'Alert saved.');

        $this->getJson('/api/watchlists')
            ->assertOk()
            ->assertJsonPath('0.stocks.0.pivot.alert_direction', 'above')
            ->assertJsonPath('0.stocks.0.change_pct', null);

        $this->deleteJson("/api/watchlists/{$id}/items/{$this->stock->id}")->assertOk();
        $this->getJson('/api/watchlists')->assertJsonCount(0, '0.stocks');
    }

    public function test_alert_on_a_stock_not_in_the_watchlist_is_404(): void
    {
        $id = $this->postJson('/api/watchlists', ['name' => 'Banks'])->json('id');

        $this->putJson("/api/watchlists/{$id}/items/{$this->stock->id}/alert", ['alert_price' => 500, 'alert_direction' => 'above'])
            ->assertNotFound()
            ->assertJsonPath('message', 'That stock is not on this watchlist.');
    }

    public function test_another_users_watchlist_is_404(): void
    {
        $theirs = User::create(['name' => 'O', 'email' => 'o@example.com', 'password' => 'password'])
            ->watchlists()->create(['name' => 'Theirs']);

        $this->postJson("/api/watchlists/{$theirs->id}/items", ['stock_id' => $this->stock->id])->assertNotFound();
    }
}
