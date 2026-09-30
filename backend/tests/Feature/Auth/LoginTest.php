<?php

namespace Tests\Feature\Auth;

use App\Events\UserLoggedIn;
use App\Models\User;
use App\Services\Auth\LoginGeolocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    private const LOGIN = 'mutation ($email: String, $password: String) {
        login(email: $email, password: $password) { token token_type user { email } }
    }';

    protected function setUp(): void
    {
        parent::setUp();
        User::create(['name' => 'Test', 'email' => 'test@example.com', 'password' => 'secret-pass']);
        $this->mock(LoginGeolocationService::class)->shouldReceive('locate')
            ->andReturn(['city' => 'Kathmandu', 'region' => 'Bagmati', 'country' => 'Nepal']);
    }

    public function test_login_issues_a_bearer_token_and_is_recorded_through_the_event(): void
    {
        $response = $this->graphQL(self::LOGIN, ['email' => 'test@example.com', 'password' => 'secret-pass'])
            ->assertJsonPath('data.login.user.email', 'test@example.com')
            ->assertJsonPath('data.login.token_type', 'Bearer');
        $token = $response->json('data.login.token');
        $this->assertNotEmpty($token);

        // No session cookie involved — this is a fresh call carrying only the token.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->graphQL('{ me { email } loginHistory { city } }')
            ->assertJsonPath('data.me.email', 'test@example.com')
            ->assertJsonCount(1, 'data.loginHistory')
            ->assertJsonPath('data.loginHistory.0.city', 'Kathmandu');
    }

    public function test_the_token_is_revoked_on_logout(): void
    {
        $token = $this->graphQL(self::LOGIN, ['email' => 'test@example.com', 'password' => 'secret-pass'])
            ->json('data.login.token');
        $authed = $this->withHeader('Authorization', "Bearer {$token}");

        $authed->graphQL('mutation { logout }')->assertJsonPath('data.logout', 'Logged out.');

        // Laravel's auth guards cache the resolved user on the guard instance, and this test's
        // calls all share one application container (unlike real requests, each with their own) —
        // without this, the earlier successful resolution would leak into the next call below.
        Auth::forgetGuards();

        // `me` is ungated (returns null rather than erroring for a guest) — a guarded field is
        // what actually proves the token no longer authenticates.
        $this->assertSame(401, $this->graphQLStatus($authed->graphQL('{ loginHistory { city } }')));
    }

    public function test_wrong_password_is_rejected_and_not_recorded(): void
    {
        Event::fake([UserLoggedIn::class]);

        $response = $this->graphQL(self::LOGIN, ['email' => 'test@example.com', 'password' => 'wrong'])
            ->assertGraphQLErrorMessage('Invalid credentials.');

        $this->assertSame(422, $this->graphQLStatus($response));
        Event::assertNotDispatched(UserLoggedIn::class);
    }

    public function test_missing_fields_come_back_as_validation_errors(): void
    {
        $response = $this->graphQL(self::LOGIN, ['email' => '', 'password' => null]);

        $this->assertSame(422, $this->graphQLStatus($response));
        $this->assertArrayHasKey('email', $response->json('errors.0.extensions.validation'));
        $this->assertArrayHasKey('password', $response->json('errors.0.extensions.validation'));
    }

    public function test_guarded_fields_need_a_login(): void
    {
        $this->graphQL('{ me { email } }')->assertJsonPath('data.me', null);

        $this->assertSame(401, $this->graphQLStatus($this->graphQL('{ loginHistory { city } }')));
    }
}
