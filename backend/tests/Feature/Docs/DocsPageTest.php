<?php

namespace Tests\Feature\Docs;

use App\Models\User;
use App\Services\Docs\ApiDocsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_console_sample_query_is_valid_against_the_schema(): void
    {
        Sanctum::actingAs(User::create(['name' => 'T', 'email' => 't@example.com', 'password' => 'password']));

        $invalid = [];

        foreach (app(ApiDocsService::class)->operations() as $ops) {
            foreach ($ops as $op) {
                if ($op['kind'] !== 'query') {
                    continue;
                }

                $message = $this->graphQL($op['console_query'], $op['sample_variables'])->json('errors.0.message') ?? '';

                // A missing sample record (404) is fine; a malformed query is not.
                if (preg_match('/Cannot query field|Unknown (type|argument)|Variable|Syntax Error|Expected/', $message)) {
                    $invalid[$op['name']] = $message;
                }
            }
        }

        $this->assertSame([], $invalid);
    }

    public function test_the_docs_page_lists_the_live_api_cron_and_trading_rules(): void
    {
        $this->get('/docs')
            ->assertOk()
            ->assertSee('placeBuyOrder')          // from the GraphQL schema
            ->assertSee('sellChecks')
            ->assertSee('/cron/generate/indicators', false) // from the registered routes
            ->assertSee('/cron/fetch/history/{symbol}', false)
            ->assertSee('Position sizing')
            ->assertSee('Trailing stop');
    }

    public function test_the_docs_page_shows_the_current_trading_limits(): void
    {
        config(['trading.min_risk_reward' => 2.75]);

        $this->get('/docs')->assertSee('2.75');
    }

    public function test_the_docs_page_can_be_switched_off(): void
    {
        config(['docs.enabled' => false]);

        $this->get('/docs')->assertNotFound();
    }

    public function test_the_docs_page_never_shows_the_cron_secret(): void
    {
        config(['services.cron.secret' => 'super-secret-value']);

        $this->get('/docs')->assertDontSee('super-secret-value');
    }

    public function test_the_api_console_lists_every_operation_and_cron_job(): void
    {
        $this->get('/console')
            ->assertOk()
            ->assertSee('Check all')
            ->assertSee('buyOrderPreview')
            ->assertSee('data-cron="/cron/generate/indicators"', false);
    }

    public function test_the_api_console_gives_each_operation_runnable_sample_values(): void
    {
        $html = $this->get('/console')->getContent();

        $this->assertStringContainsString('NABIL', $html);          // sample symbol
        $this->assertStringContainsString('portfolio_id', $html);   // sample variables for portfolio operations
    }

    public function test_the_api_console_follows_the_docs_switch(): void
    {
        config(['docs.enabled' => false]);

        $this->get('/console')->assertNotFound();
    }
}
