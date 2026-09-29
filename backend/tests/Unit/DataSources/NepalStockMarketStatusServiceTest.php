<?php

namespace Tests\Unit\DataSources;

use App\Services\DataSources\NepalStock\NepalStockMarketStatusService;
use App\Services\DataSources\NepalStock\NepalStockTokenService;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class NepalStockMarketStatusServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $tokens = Mockery::mock(NepalStockTokenService::class);
        $tokens->shouldReceive('getAccessToken')->andReturn('tok');
        $this->app->instance(NepalStockTokenService::class, $tokens);
    }

    private function marketReturns(array $body): NepalStockMarketStatusService
    {
        Http::fake(['*/api/nots/nepse-data/market-open' => Http::response($body)]);

        return app(NepalStockMarketStatusService::class);
    }

    public function test_open_market_returns_todays_session_date(): void
    {
        $status = $this->marketReturns(['isOpen' => 'OPEN', 'asOf' => '2026-09-29T13:45:00', 'id' => 80]);

        $this->assertTrue($status->isOpen());
        $this->assertSame('2026-09-29', $status->lastOpenDate());
    }

    public function test_closed_market_returns_the_latest_session_date(): void
    {
        $status = $this->marketReturns(['isOpen' => 'CLOSE', 'asOf' => '2026-09-28T15:00:00', 'id' => 80]);

        $this->assertFalse($status->isOpen());
        $this->assertSame('2026-09-28', $status->lastOpenDate());
    }

    public function test_current_gives_both_from_one_request(): void
    {
        $status = $this->marketReturns(['isOpen' => 'CLOSE', 'asOf' => '2026-09-28T15:00:00']);

        $this->assertSame(['open' => false, 'last_open_date' => '2026-09-28'], $status->current());
        Http::assertSentCount(1);
    }

    public function test_an_unreadable_date_returns_an_empty_string(): void
    {
        $this->assertSame('', $this->marketReturns(['isOpen' => 'OPEN'])->lastOpenDate());
        $this->assertSame('', $this->marketReturns(['isOpen' => 'OPEN', 'asOf' => 'not-a-date'])->lastOpenDate());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
