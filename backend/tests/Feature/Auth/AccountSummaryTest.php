<?php

namespace Tests\Feature\Auth;

use App\Models\LoginHistory;
use App\Models\Portfolio;
use App\Models\PortfolioTransaction;
use App\Models\SavedScreen;
use App\Models\Stock;
use App\Models\User;
use App\Models\Watchlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The Profile page's numbers, and signing out the other devices. */
class AccountSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email = 'me@example.com'): User
    {
        return User::create(['name' => 'Me', 'email' => $email, 'password' => 'password']);
    }

    private function asBearer(string $token): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    public function test_the_summary_counts_only_this_accounts_own_things(): void
    {
        $me = $this->user();
        $other = $this->user('other@example.com');
        $stock = Stock::create(['symbol' => 'NABIL', 'company_name' => 'Nabil', 'is_active' => true]);

        $portfolio = Portfolio::create(['user_id' => $me->id, 'name' => 'Main']);
        PortfolioTransaction::create(['portfolio_id' => $portfolio->id, 'stock_id' => $stock->id, 'type' => 'buy', 'quantity' => 10, 'price' => 500, 'fees' => 0, 'transaction_date' => '2026-10-01']);
        PortfolioTransaction::create(['portfolio_id' => $portfolio->id, 'stock_id' => $stock->id, 'type' => 'sell', 'quantity' => 5, 'price' => 520, 'fees' => 0, 'transaction_date' => '2026-10-05']);
        Portfolio::create(['user_id' => $other->id, 'name' => 'Not mine']);

        $watchlist = Watchlist::create(['user_id' => $me->id, 'name' => 'Banks']);
        $watchlist->stocks()->attach($stock->id);
        Watchlist::create(['user_id' => $other->id, 'name' => 'Not mine'])->stocks()->attach($stock->id);

        SavedScreen::create(['user_id' => $me->id, 'name' => 'Oversold', 'filters' => ['rsi_max' => 30]]);

        foreach (range(1, 7) as $i) {
            LoginHistory::create(['user_id' => $me->id, 'ip_address' => "10.0.0.{$i}", 'user_agent' => 'Mozilla/5.0', 'logged_in_at' => now()->subDays(8 - $i)]);
        }
        LoginHistory::create(['user_id' => $other->id, 'ip_address' => '9.9.9.9', 'user_agent' => 'x', 'logged_in_at' => now()]);

        $me->createToken('phone');
        $token = $me->createToken('laptop')->plainTextToken;

        $summary = $this->asBearer($token)->graphQL('{ accountSummary { portfolios transactions watchlists watchlist_stocks saved_screens active_sessions total_logins recent_logins { ip_address } } }')
            ->assertJsonMissingPath('errors')
            ->json('data.accountSummary');

        $this->assertSame(1, $summary['portfolios']);
        $this->assertSame(2, $summary['transactions']);
        $this->assertSame(1, $summary['watchlists']);
        $this->assertSame(1, $summary['watchlist_stocks']);
        $this->assertSame(1, $summary['saved_screens']);
        $this->assertSame(2, $summary['active_sessions']);
        $this->assertSame(7, $summary['total_logins']);
        $this->assertSame(['10.0.0.7', '10.0.0.6', '10.0.0.5', '10.0.0.4', '10.0.0.3'], array_column($summary['recent_logins'], 'ip_address')); // five, newest first, only mine
    }

    public function test_a_brand_new_account_has_zeros_and_no_logins_not_an_error(): void
    {
        $token = $this->user()->createToken('only')->plainTextToken;

        $summary = $this->asBearer($token)->graphQL('{ accountSummary { portfolios transactions watchlists watchlist_stocks saved_screens active_sessions total_logins recent_logins { id } } }')
            ->assertJsonMissingPath('errors')
            ->json('data.accountSummary');

        $this->assertSame([0, 0, 0, 0, 0, 1, 0], [$summary['portfolios'], $summary['transactions'], $summary['watchlists'], $summary['watchlist_stocks'], $summary['saved_screens'], $summary['active_sessions'], $summary['total_logins']]);
        $this->assertSame([], $summary['recent_logins']);
    }

    public function test_signing_out_other_devices_keeps_this_one_and_other_accounts_untouched(): void
    {
        $me = $this->user();
        $other = $this->user('other@example.com');
        $me->createToken('phone');
        $me->createToken('tablet');
        $current = $me->createToken('laptop')->plainTextToken;
        $other->createToken('theirs');

        $this->asBearer($current)->graphQL('mutation { logoutOtherSessions }')->assertJsonPath('data.logoutOtherSessions', 2);

        $this->assertSame(1, $me->tokens()->count());
        $this->assertSame('laptop', $me->tokens()->first()->name);
        $this->assertSame(1, $other->tokens()->count());

        // The device that did it is still signed in.
        $this->asBearer($current)->graphQL('{ accountSummary { active_sessions } }')->assertJsonPath('data.accountSummary.active_sessions', 1);
    }

    public function test_it_needs_a_login(): void
    {
        $this->assertSame(401, $this->graphQLStatus($this->graphQL('{ accountSummary { portfolios } }')));
        $this->assertSame(401, $this->graphQLStatus($this->graphQL('mutation { logoutOtherSessions }')));
    }
}
