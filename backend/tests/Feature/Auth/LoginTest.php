<?php

namespace Tests\Feature\Auth;

use App\Events\UserLoggedIn;
use App\Models\User;
use App\Services\Auth\LoginGeolocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    private const LOGIN = 'mutation ($email: String, $password: String) { login(email: $email, password: $password) { email } }';

    protected function setUp(): void
    {
        parent::setUp();
        // Login is session-based: only a request from the SPA's own origin
        // (a SANCTUM_STATEFUL_DOMAINS entry) gets a session, like the real frontend.
        config(['sanctum.stateful' => ['localhost']]);
        $this->withHeader('Referer', 'http://localhost/');
        User::create(['name' => 'Test', 'email' => 'test@example.com', 'password' => 'secret-pass']);
        $this->mock(LoginGeolocationService::class)->shouldReceive('locate')
            ->andReturn(['city' => 'Kathmandu', 'region' => 'Bagmati', 'country' => 'Nepal']);
    }

    public function test_login_is_recorded_through_the_event(): void
    {
        $this->graphQL(self::LOGIN, ['email' => 'test@example.com', 'password' => 'secret-pass'])
            ->assertJsonPath('data.login.email', 'test@example.com');

        $this->graphQL('{ me { email } loginHistory { city } }')
            ->assertJsonPath('data.me.email', 'test@example.com')
            ->assertJsonCount(1, 'data.loginHistory')
            ->assertJsonPath('data.loginHistory.0.city', 'Kathmandu');
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
