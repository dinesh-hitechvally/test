<?php

namespace Tests\Feature\Portfolio;

use App\Models\Portfolio;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PortfolioEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Portfolio $portfolio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'Test', 'email' => 'test@example.com', 'password' => 'password']);
        $this->portfolio = $this->user->portfolios()->create(['name' => 'Main Portfolio']);
        Sanctum::actingAs($this->user);
    }

    public function test_store_portfolio_validates_through_its_form_request(): void
    {
        $this->postJson('/api/portfolios', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->postJson('/api/portfolios', ['name' => 'Second'])
            ->assertCreated()
            ->assertJsonPath('name', 'Second');
    }

    public function test_store_transaction_rejects_an_invalid_payload(): void
    {
        $this->postJson("/api/portfolios/{$this->portfolio->id}/transactions", ['type' => 'hold'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['stock_id', 'type', 'quantity', 'price', 'transaction_date']);
    }

    public function test_transactions_are_listed_newest_first(): void
    {
        $stock = Stock::create(['symbol' => 'TEST', 'company_name' => 'Test Co', 'is_active' => true]);
        $this->transaction($stock, '2024-01-01', 10);
        $this->transaction($stock, '2024-03-01', 5);

        $this->getJson("/api/portfolios/{$this->portfolio->id}/transactions")
            ->assertOk()
            ->assertJsonPath('0.quantity', 5)
            ->assertJsonPath('0.stock.symbol', 'TEST');
    }

    public function test_csv_export_contains_both_sections(): void
    {
        $stock = Stock::create(['symbol' => 'TEST', 'company_name' => 'Test Co', 'is_active' => true]);
        $this->transaction($stock, '2024-01-01', 10);

        $response = $this->get("/api/portfolios/{$this->portfolio->id}/export");

        $response->assertOk()->assertDownload('main-portfolio-report-'.now()->toDateString().'.csv');
        $csv = $response->streamedContent();
        $this->assertStringContainsString('HOLDINGS', $csv);
        $this->assertStringContainsString('Symbol,Company,Quantity', $csv);
        $this->assertStringContainsString('2024-01-01,TEST,buy,10', $csv);
    }

    public function test_exports_are_scoped_to_the_owner(): void
    {
        $other = User::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => 'password']);
        $theirs = $other->portfolios()->create(['name' => 'Theirs']);

        $this->get("/api/portfolios/{$theirs->id}/export")->assertNotFound();
        $this->get("/api/portfolios/{$theirs->id}/export-pdf")->assertNotFound();
        $this->get("/api/portfolios/{$theirs->id}/export-excel")->assertNotFound();
    }

    private function transaction(Stock $stock, string $date, int $quantity): void
    {
        $this->portfolio->transactions()->create([
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => $quantity,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => $date,
        ]);
    }
}
