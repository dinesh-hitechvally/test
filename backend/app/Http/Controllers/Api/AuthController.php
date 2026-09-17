<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::attempt($credentials, remember: true)) {
            return response()->json(['message' => 'Invalid credentials.'], 422);
        }

        $request->session()->regenerate();

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

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email,'.$user->id],
        ]);

        $user->update($validated);

        return response()->json(['user' => $user->fresh()]);
    }

    public function updatePassword(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user->update(['password' => Hash::make($validated['password'])]);

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
    public function forgotPassword(Request $request)
    {
        $validated = $request->validate(['email' => ['required', 'email']]);

        $status = PasswordBroker::sendResetLink($validated);

        return $status === PasswordBroker::RESET_LINK_SENT
            ? response()->json(['message' => __($status)])
            : response()->json(['message' => __($status)], 422);
    }

    public function resetPassword(Request $request)
    {
        $validated = $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $status = PasswordBroker::reset($validated, function ($user, $password) {
            $user->update(['password' => Hash::make($password)]);
        });

        return $status === PasswordBroker::PASSWORD_RESET
            ? response()->json(['message' => __($status)])
            : response()->json(['message' => __($status)], 422);
    }
}
