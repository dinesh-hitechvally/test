<?php

namespace Tests\Feature\Portfolio;

use App\Models\Portfolio;
use App\Models\PositionTarget;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PortfolioEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private const ADD_TRANSACTION = 'mutation ($p: Int!, $stock_id: Int, $type: String, $quantity: Float, $price: Float, $transaction_date: String) {
        addTransaction(portfolio_id: $p, stock_id: $stock_id, type: $type, quantity: $quantity, price: $price, transaction_date: $transaction_date) {
            fees stock { symbol }
        }
    }';

    private const SET_TARGET = 'mutation ($p: Int!, $s: Int!, $stop: Float, $target: Float) {
        setPositionTarget(portfolio_id: $p, stock_id: $s, stop_loss: $stop, target_price: $target) { stop_loss }
    }';

    private User $user;

    private Portfolio $portfolio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'Test', 'email' => 'test@example.com', 'password' => 'password']);
        $this->portfolio = $this->user->portfolios()->create(['name' => 'Main Portfolio']);
        Sanctum::actingAs($this->user);
    }

    public function test_create_portfolio_validates_through_its_form_request(): void
    {
        $response = $this->graphQL('mutation { createPortfolio { id } }');
        $this->assertSame(422, $this->graphQLStatus($response));
        $this->assertArrayHasKey('name', $response->json('errors.0.extensions.validation'));

        $this->graphQL('mutation { createPortfolio(name: "Second") { name } }')->assertJsonPath('data.createPortfolio.name', 'Second');
    }

    public function test_add_transaction_rejects_an_invalid_payload(): void
    {
        $response = $this->graphQL(self::ADD_TRANSACTION, ['p' => $this->portfolio->id, 'type' => 'hold']);

        $this->assertSame(422, $this->graphQLStatus($response));
        $this->assertEqualsCanonicalizing(
            ['stock_id', 'type', 'quantity', 'price', 'transaction_date'],
            array_keys($response->json('errors.0.extensions.validation')),
        );
    }

    public function test_a_fractional_quantity_gets_the_validation_message(): void
    {
        $stock = Stock::create(['symbol' => 'TEST', 'company_name' => 'Test Co', 'is_active' => true]);

        $response = $this->graphQL(self::ADD_TRANSACTION, [
            'p' => $this->portfolio->id, 'stock_id' => $stock->id, 'type' => 'buy', 'quantity' => 1.5, 'price' => 100, 'transaction_date' => '2024-01-02',
        ]);

        $this->assertSame(422, $this->graphQLStatus($response));
        $this->assertSame(['quantity'], array_keys($response->json('errors.0.extensions.validation')));
    }

    public function test_buying_then_overselling_is_rejected_with_the_rule_message(): void
    {
        $stock = Stock::create(['symbol' => 'TEST', 'company_name' => 'Test Co', 'is_active' => true]);
        $trade = ['p' => $this->portfolio->id, 'stock_id' => $stock->id, 'price' => 100, 'transaction_date' => '2024-01-02'];

        $this->graphQL(self::ADD_TRANSACTION, [...$trade, 'type' => 'buy', 'quantity' => 10])
            ->assertJsonPath('data.addTransaction.stock.symbol', 'TEST')
            ->assertJsonPath('data.addTransaction.fees', fn ($v) => (float) $v === 0.0);

        $response = $this->graphQL(self::ADD_TRANSACTION, [...$trade, 'type' => 'sell', 'quantity' => 50]);
        $this->assertSame(422, $this->graphQLStatus($response));
        $this->assertNotEmpty($response->json('errors.0.message'));
        $this->assertNull($response->json('errors.0.extensions.validation')); // a rule message, not a field-validation error
    }

    public function test_set_target_upserts_the_levels(): void
    {
        $stock = Stock::create(['symbol' => 'TEST', 'company_name' => 'Test Co', 'is_active' => true]);
        $vars = ['p' => $this->portfolio->id, 's' => $stock->id];

        $this->graphQL(self::SET_TARGET, [...$vars, 'stop' => 90, 'target' => 150])
            ->assertJsonPath('data.setPositionTarget.stop_loss', fn ($v) => (float) $v === 90.0);
        $this->graphQL(self::SET_TARGET, [...$vars, 'stop' => 95, 'target' => null])
            ->assertJsonPath('data.setPositionTarget.stop_loss', fn ($v) => (float) $v === 95.0);

        $this->assertSame(1, PositionTarget::count());
    }

    public function test_transactions_are_listed_newest_first_and_can_be_deleted(): void
    {
        $stock = Stock::create(['symbol' => 'TEST', 'company_name' => 'Test Co', 'is_active' => true]);
        $this->transaction($stock, '2024-01-01', 10);
        $this->transaction($stock, '2024-03-01', 5);
        $list = 'query ($p: Int!) { portfolioTransactions(portfolio_id: $p) { id quantity stock { symbol } } }';

        $response = $this->graphQL($list, ['p' => $this->portfolio->id])
            ->assertJsonPath('data.portfolioTransactions.0.quantity', 5)
            ->assertJsonPath('data.portfolioTransactions.0.stock.symbol', 'TEST');

        $this->graphQL('mutation ($p: Int!, $t: Int!) { deleteTransaction(portfolio_id: $p, transaction_id: $t) }',
            ['p' => $this->portfolio->id, 't' => $response->json('data.portfolioTransactions.0.id')])->assertJsonMissingPath('errors');
        $this->graphQL($list, ['p' => $this->portfolio->id])->assertJsonCount(1, 'data.portfolioTransactions');
    }

    public function test_another_users_portfolio_is_404(): void
    {
        $theirs = User::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => 'password'])
            ->portfolios()->create(['name' => 'Theirs']);

        $response = $this->graphQL('query ($id: Int!) { portfolio(id: $id) { summary { total_invested } } }', ['id' => $theirs->id]);

        $this->assertSame(404, $this->graphQLStatus($response));
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
