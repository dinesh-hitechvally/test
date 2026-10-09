<?php

namespace App\GraphQL\Resolvers;

use App\Events\UserLoggedIn;
use App\GraphQL\ApiError;
use App\GraphQL\Resolver;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Models\PortfolioTransaction;
use App\Models\User;
use App\Services\Auth\AccountService;
use App\Services\Auth\LoginHistoryService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Login, logout, password reset and the current user's account. No session/cookie involved —
 * the SPA authenticates every request with a Sanctum bearer token (issued by login(), sent as
 * Authorization from then on, revoked by logout()). See config/lighthouse.php for why /graphql
 * carries no stateful/CSRF middleware.
 */
class AuthResolver extends Resolver
{
    public function __construct(private readonly LoginHistoryService $history, private readonly AccountService $account) {}

    /** Recording the login (IP, device, location) follows via UserLoggedIn → RecordLoginHistory. */
    public function login($root, array $args): array
    {
        $credentials = $this->validated(LoginRequest::class, $args);

        if (! Auth::guard('web')->validate($credentials)) {
            throw new ApiError('Invalid credentials.', 422);
        }

        $user = User::where('email', $credentials['email'])->firstOrFail();

        UserLoggedIn::dispatch(
            $user,
            request()->ip(),
            request()->userAgent()
        );

        return [
            'token' => $user->createToken('spa')->plainTextToken,
            'token_type' => 'Bearer',
            'user' => $this->account->present($user),
        ];
    }

    public function logout(): string
    {
        // Only a real token is revocable — Sanctum resolves a session-authenticated request to
        // its TransientToken stub instead, which isn't a stored row, nothing to delete. Kept as a
        // safety check even though every request now arrives via a real token.
        $token = $this->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return 'Logged out.';
    }

    public function me(): ?array
    {
        return auth()->user() ? $this->account->present(auth()->user()) : null;
    }

    public function updateProfile($root, array $args): array
    {
        $user = $this->account->save($this->user(), $this->validated(UpdateProfileRequest::class, $args));

        return $this->account->present($user);
    }

    public function updatePassword($root, array $args): string
    {
        $this->user()->update([
            'password' => Hash::make($this->validated(UpdatePasswordRequest::class, $args)['password']),
            'password_changed_at' => now(),
        ]);

        return 'Password updated.';
    }

    /** The reset link points at the frontend (AppServiceProvider::boot()). */
    public function forgotPassword($root, array $args): string
    {
        $status = PasswordBroker::sendResetLink($this->validated(ForgotPasswordRequest::class, $args));

        return $status === PasswordBroker::RESET_LINK_SENT ? __($status) : throw new ApiError(__($status), 422);
    }

    public function resetPassword($root, array $args): string
    {
        $status = PasswordBroker::reset($this->validated(ResetPasswordRequest::class, $args), function ($user, $password) {
            $user->update(['password' => Hash::make($password), 'password_changed_at' => now()]);
        });

        return $status === PasswordBroker::PASSWORD_RESET ? __($status) : throw new ApiError(__($status), 422);
    }

    public function loginHistory(): array
    {
        return $this->plain($this->history->recent($this->user()));
    }

    /** The Profile page: what the account holds, and where it has signed in. */
    public function accountSummary(): array
    {
        $user = $this->user();
        $portfolioIds = $user->portfolios()->select('id');
        $watchlistIds = $user->watchlists()->select('id');

        return [
            'portfolios' => $user->portfolios()->count(),
            'transactions' => PortfolioTransaction::whereIn('portfolio_id', $portfolioIds)->count(),
            'watchlists' => $user->watchlists()->count(),
            'watchlist_stocks' => DB::table('watchlist_items')->whereIn('watchlist_id', $watchlistIds)->count(),
            'saved_screens' => $user->savedScreens()->count(),
            'active_sessions' => $user->tokens()->count(),
            'total_logins' => $user->loginHistories()->count(),
            'recent_logins' => $this->plain($this->history->recent($user)->take(5)->values()),
        ];
    }

    /** Signs the account out everywhere except the device making this request; returns how many sessions ended. */
    public function logoutOtherSessions(): int
    {
        $user = $this->user();
        $current = $user->currentAccessToken();
        $others = $user->tokens();

        if ($current instanceof PersonalAccessToken) {
            $others->whereKeyNot($current->getKey());
        }

        return $others->delete();
    }

    public function users(): array
    {
        return $this->plain(User::select('id', 'name', 'email', 'created_at')->orderBy('created_at')->get());
    }
}
