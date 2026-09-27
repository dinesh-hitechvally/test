<?php

namespace App\Http\Controllers\Api;

use App\Events\UserLoggedIn;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Services\Auth\LoginHistoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;

class AuthController extends Controller
{
    /** Recording the login (IP, device, location) follows via UserLoggedIn → RecordLoginHistory. */
    public function login(LoginRequest $request)
    {
        if (! Auth::attempt($request->validated(), remember: true)) {
            return response()->json(['message' => 'Invalid credentials.'], 422);
        }

        $request->session()->regenerate();

        UserLoggedIn::dispatch(Auth::user(), $request->ip(), $request->userAgent());

        return response()->json(['user' => Auth::user()]);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out.']);
    }

    public function user(Request $request)
    {
        return response()->json(['user' => $request->user()]);
    }

    public function updateProfile(UpdateProfileRequest $request)
    {
        $user = $request->user();

        $user->update($request->validated());

        return response()->json(['user' => $user->fresh()]);
    }

    public function loginHistory(Request $request, LoginHistoryService $history)
    {
        return response()->json($history->recent($request->user()));
    }

    public function updatePassword(UpdatePasswordRequest $request)
    {
        $request->user()->update(['password' => Hash::make($request->validated('password'))]);

        return response()->json(['message' => 'Password updated.']);
    }

    /**
     * Sends a reset link to the given email if an account exists for it —
     * the actual link URL points at the frontend (see
     * AppServiceProvider::boot()'s ResetPassword::createUrlUsing()), not a
     * backend route, since this is an API-only backend behind a separate
     * Vue SPA. Delivery goes through whatever MAIL_MAILER is configured
     * (currently 'log' in dev, so the link lands in storage/logs/laravel.log
     * instead of a real inbox until real SMTP is configured).
     */
    public function forgotPassword(ForgotPasswordRequest $request)
    {
        $status = PasswordBroker::sendResetLink($request->validated());

        return $status === PasswordBroker::RESET_LINK_SENT
            ? response()->json(['message' => __($status)])
            : response()->json(['message' => __($status)], 422);
    }

    public function resetPassword(ResetPasswordRequest $request)
    {
        $status = PasswordBroker::reset($request->validated(), function ($user, $password) {
            $user->update(['password' => Hash::make($password)]);
        });

        return $status === PasswordBroker::PASSWORD_RESET
            ? response()->json(['message' => __($status)])
            : response()->json(['message' => __($status)], 422);
    }
}
