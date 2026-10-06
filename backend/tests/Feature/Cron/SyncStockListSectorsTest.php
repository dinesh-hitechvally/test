<?php

namespace Tests\Feature\Cron;

use App\Models\Sector;
use App\Models\Stock;
use App\Services\DataSources\NepalStock\NepalStockClient;
use App\Services\DataSources\NepalStock\NepalStockSecurityResolver;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Tests\TestCase;

/** The stock list sync fills in sectors from NEPSE's company list, instead of one request per stock. */
class SyncStockListSectorsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.cron.secret' => 'test-secret']);
    }

    /**
     * nepalstock.com stand-in: a securities list (no sectors) and a companies list (with sectors, and
     * not covering promoter / preference shares like NABILP, CHCLPO). $companies = null makes that call fail.
     */
    private function nepse(?array $companies): void
    {
        $this->app->instance(NepalStockSecurityResolver::class, new class(new class extends NepalStockClient
        {
            public ?array $companies = null;

            public array $paths = [];

            public function __construct() {}

            public function get(string $path, array $query = [], int $timeout = 20): Response
            {
                $this->paths[] = $path;

                if (str_contains($path, '/company/list')) {
                    return $this->companies === null ? self::reply(['error' => 'server error'], 500) : self::reply($this->companies);
                }

                return self::reply([
                    ['id' => 1, 'symbol' => 'NABIL', 'securityName' => 'Nabil Bank', 'activeStatus' => 'A'],
                    ['id' => 2, 'symbol' => 'CHCL', 'securityName' => 'Chilime', 'activeStatus' => 'A'],
                    ['id' => 3, 'symbol' => 'NABILP', 'securityName' => 'Nabil Promoter', 'activeStatus' => 'A'],
                    ['id' => 4, 'symbol' => 'CHCLPO', 'securityName' => 'Chilime Promoter', 'activeStatus' => 'A'],
                    ['id' => 5, 'symbol' => 'LONEPO', 'securityName' => 'No parent company', 'activeStatus' => 'A'],
                ]);
            }

            private static function reply(array $body, int $status = 200): Response
            {
                return new Response(new PsrResponse($status, ['Content-Type' => 'application/json'], json_encode($body)));
            }
        }) extends NepalStockSecurityResolver
        {
            public function __construct(public NepalStockClient $stub)
            {
                parent::__construct($stub);
            }
        });

        $this->app->make(NepalStockSecurityResolver::class)->stub->companies = $companies;
    }

    public function test_new_and_existing_stocks_get_their_sector_from_the_company_list(): void
    {
        $this->nepse([
            ['symbol' => 'NABIL', 'sectorName' => 'Commercial Banks', 'instrumentType' => 'Equity'],
            ['symbol' => 'CHCL', 'sectorName' => ' Hydro Power ', 'instrumentType' => 'Equity'], // stray spaces are trimmed
        ]);
        Stock::create(['symbol' => 'CHCL', 'company_name' => 'Chilime', 'is_active' => true]); // exists, no sector yet

        $this->get('/cron/fetch/stock-list?key=test-secret')
            ->assertOk()
            ->assertSeeText('4 new stock(s) created, 1 already existed, 4 given a sector (2 of them promoter/preference shares, taken from their parent company), 2 had their instrument type set.');

        $this->assertSame('Commercial Banks', Stock::firstWhere('symbol', 'NABIL')->sector->name);
        $this->assertSame('Hydro Power', Stock::firstWhere('symbol', 'CHCL')->sector->name);
        $this->assertSame('Equity', Stock::firstWhere('symbol', 'NABIL')->instrument_type);
        $this->assertSame('Equity', Stock::firstWhere('symbol', 'CHCL')->instrument_type);

        // Promoter shares are not in the company list: the sector comes from the parent company ("P" / "PO" removed).
        $this->assertSame('Commercial Banks', Stock::firstWhere('symbol', 'NABILP')->sector->name);
        $this->assertSame('Hydro Power', Stock::firstWhere('symbol', 'CHCLPO')->sector->name);
        $this->assertNull(Stock::firstWhere('symbol', 'NABILP')->instrument_type); // the type is not guessed

        // No parent company in the list: nothing is guessed, the stock is left without a sector.
        $this->assertNull(Stock::firstWhere('symbol', 'LONEPO')->sector_id);
        $this->assertSame(1, Sector::where('name', 'Hydro Power')->count());
    }

    public function test_a_stock_that_already_has_a_sector_is_not_changed(): void
    {
        $this->nepse([['symbol' => 'NABIL', 'sectorName' => 'Commercial Banks']]);
        $mine = Sector::create(['name' => 'My Own Grouping']);
        Stock::create(['symbol' => 'NABIL', 'company_name' => 'Nabil', 'is_active' => true, 'sector_id' => $mine->id]);

        // NABIL keeps the sector it has; only its promoter share NABILP (not a company) is given one.
        $this->get('/cron/fetch/stock-list?key=test-secret')->assertSeeText('1 given a sector (1 of them promoter/preference shares');

        $this->assertSame('My Own Grouping', Stock::firstWhere('symbol', 'NABIL')->sector->name);
    }

    public function test_the_instrument_type_follows_the_company_list_and_is_kept_when_the_list_has_none(): void
    {
        $this->nepse([
            ['symbol' => 'NABIL', 'sectorName' => 'Commercial Banks', 'instrumentType' => 'Mutual Fund'], // changed upstream
            ['symbol' => 'CHCL', 'sectorName' => 'Hydro Power', 'instrumentType' => ''],                  // list has no type
        ]);
        Stock::create(['symbol' => 'NABIL', 'company_name' => 'Nabil', 'is_active' => true, 'instrument_type' => 'Equity']);
        Stock::create(['symbol' => 'CHCL', 'company_name' => 'Chilime', 'is_active' => true, 'instrument_type' => 'Equity']);

        $this->get('/cron/fetch/stock-list?key=test-secret')->assertSeeText('1 had their instrument type set');

        $this->assertSame('Mutual Fund', Stock::firstWhere('symbol', 'NABIL')->instrument_type);
        $this->assertSame('Equity', Stock::firstWhere('symbol', 'CHCL')->instrument_type); // not blanked
    }

    public function test_the_stock_list_still_syncs_when_the_company_list_call_fails(): void
    {
        $this->nepse(null);

        $this->get('/cron/fetch/stock-list?key=test-secret')
            ->assertOk()
            ->assertSeeText('5 new stock(s) created, 0 already existed, 0 given a sector (0 of them promoter/preference shares, taken from their parent company), 0 had their instrument type set.')
            ->assertSeeText('[ok]');

        $this->assertSame(5, Stock::count());
        $this->assertSame(0, Stock::whereNotNull('sector_id')->count()); // they stay without one; the next stock-list run tries again
    }
}
