<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthenticateUser
{
    public function handle(string $email, string $password, string $ip): User
    {
        $key = 'login:'.hash('sha256', Str::lower($email).'|'.$ip);
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => __('auth.throttled')]);
        }
        if (! Auth::attempt(['email' => $email, 'password' => $password])) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }
        RateLimiter::clear($key);
        $user = Auth::user();
        assert($user instanceof User);

        return $user;
    }
}
