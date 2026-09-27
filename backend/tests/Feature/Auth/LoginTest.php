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
        $this->postJson('/api/login', ['email' => 'test@example.com', 'password' => 'secret-pass'])
            ->assertOk()
            ->assertJsonPath('user.email', 'test@example.com');

        $this->getJson('/api/user/login-history')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.city', 'Kathmandu');
    }

    public function test_wrong_password_is_rejected_and_not_recorded(): void
    {
        Event::fake([UserLoggedIn::class]);

        $this->postJson('/api/login', ['email' => 'test@example.com', 'password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Invalid credentials.');

        Event::assertNotDispatched(UserLoggedIn::class);
    }
}
