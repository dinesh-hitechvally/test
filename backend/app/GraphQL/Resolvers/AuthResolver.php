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
use App\Models\User;
use App\Services\Auth\LoginHistoryService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;

/**
 * Login, logout, password reset and the current user's account. Uses the
 * same session-cookie auth as before (Sanctum's stateful middleware runs on
 * /graphql — see config/lighthouse.php).
 */
class AuthResolver extends Resolver
{
    public function __construct(private readonly LoginHistoryService $history) {}

    /** Recording the login (IP, device, location) follows via UserLoggedIn → RecordLoginHistory. */
    public function login($root, array $args): array
    {
        if (! Auth::attempt($this->validated(LoginRequest::class, $args), remember: true)) {
            throw new ApiError('Invalid credentials.', 422);
        }

        request()->session()->regenerate();

        UserLoggedIn::dispatch(Auth::user(), request()->ip(), request()->userAgent());

        return $this->plain(Auth::user());
    }

    public function logout(): string
    {
        Auth::guard('web')->logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return 'Logged out.';
    }

    public function me(): ?array
    {
        return $this->plain(auth()->user());
    }

    public function updateProfile($root, array $args): array
    {
        $user = $this->user();
        $user->update($this->validated(UpdateProfileRequest::class, $args));

        return $this->plain($user->fresh());
    }

    public function updatePassword($root, array $args): string
    {
        $this->user()->update(['password' => Hash::make($this->validated(UpdatePasswordRequest::class, $args)['password'])]);

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
            $user->update(['password' => Hash::make($password)]);
        });

        return $status === PasswordBroker::PASSWORD_RESET ? __($status) : throw new ApiError(__($status), 422);
    }

    public function loginHistory(): array
    {
        return $this->plain($this->history->recent($this->user()));
    }

    public function users(): array
    {
        return $this->plain(User::select('id', 'name', 'email', 'created_at')->orderBy('created_at')->get());
    }
}
