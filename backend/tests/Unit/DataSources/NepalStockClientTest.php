<?php

namespace Tests\Unit\DataSources;

use App\Services\DataSources\NepalStock\NepalStockClient;
use App\Services\DataSources\NepalStock\NepalStockSecurityResolver;
use App\Services\DataSources\NepalStock\NepalStockTokenService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class NepalStockClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $tokens = Mockery::mock(NepalStockTokenService::class);
        $tokens->shouldReceive('getAccessToken')->andReturn('tok-1', 'tok-2', 'tok-3');
        $this->app->instance(NepalStockTokenService::class, $tokens);
    }

    public function test_every_request_is_authenticated_with_a_fresh_token(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);
        $client = app(NepalStockClient::class);

        $client->get('/api/nots/nepse-index');
        $client->get('/api/nots/nepse-index');

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Salter tok-1')
            && $r->hasHeader('Referer', 'https://www.nepalstock.com/')
            && str_starts_with($r->header('User-Agent')[0], 'Mozilla/5.0'));
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Salter tok-2'));
    }

    public function test_a_query_string_already_in_the_path_is_kept(): void
    {
        Http::fake(['*' => Http::response([])]);

        app(NepalStockClient::class)->get('/api/nots/security?nonDelisted=true');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://www.nepalstock.com/api/nots/security?nonDelisted=true');
    }

    public function test_query_parameters_are_added_when_given(): void
    {
        Http::fake(['*' => Http::response([])]);

        app(NepalStockClient::class)->get('/api/nots/market/history/security/131', ['page' => 2, 'size' => 1000]);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://www.nepalstock.com/api/nots/market/history/security/131?page=2&size=1000');
    }

    public function test_verify_fails_loudly_when_the_api_rejects_the_token(): void
    {
        Http::fake(['*' => Http::response(['error' => 'unauthorized'], 401)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Token minted but the API rejected it (HTTP 401)');

        app(NepalStockClient::class)->verify();
    }

    public function test_services_can_now_be_tested_without_the_real_site(): void
    {
        Http::fake(['*/api/nots/security/131' => Http::response(['securityData' => ['symbol' => 'NABIL'], 'sectorName' => 'Commercial Banks', 'security' => ['sectorMaster' => ['sectorDescription' => 'Commercial Banks']]])]);

        $response = app(NepalStockClient::class)->get('/api/nots/security/131');

        $this->assertSame('NABIL', $response->json('securityData.symbol'));
        $this->assertInstanceOf(NepalStockSecurityResolver::class, app(NepalStockSecurityResolver::class));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
