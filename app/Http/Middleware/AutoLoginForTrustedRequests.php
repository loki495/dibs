<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opt-in, off by default: signs an existing account in automatically for the deployment owner so they
 * skip the login page. Works on any deployment (demo, dev or real); it only ever signs in
 * config('dibs.auto_login_email') - never creates an account. In demo mode that defaults to the shared
 * demo account.
 *
 * Never trusts $request->ip()/X-Forwarded-For alone (a client can append to that chain). A request counts
 * as LAN when auto_login_lan is on, it carries no Cloudflare edge header (CF-Connecting-IP/CF-Ray, which
 * only Cloudflare adds) and its peer is a private address - only valid when nothing but the tunnel and the
 * LAN can reach this app. A request that did come through Cloudflare is never auto-logged-in: its headers can be
 * forged by anyone who reaches the app directly, so it uses the normal login.
 * Must run after StartSession (and ResolveDemoDatabase in demo mode) and before the auth gate.
 */
class AutoLoginForTrustedRequests
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $email = $this->accountEmail();

        if ($email !== null && ! Auth::check() && $this->isTrusted($request)) {
            $user = User::query()->where('email', $email)->first();

            if ($user !== null) {
                Auth::login($user);
            }
        }

        return $next($request);
    }

    private function accountEmail(): ?string
    {
        $email = config('dibs.auto_login_email') ?: (config('dibs.demo_mode') ? config('dibs.demo_login_email') : null);

        return is_string($email) && $email !== '' ? $email : null;
    }

    private function isTrusted(Request $request): bool
    {
        if ($request->headers->has('CF-Connecting-IP') || $request->headers->has('CF-Ray')) {
            return false;
        }

        $ip = $request->ip();

        return (bool) config('dibs.auto_login_lan')
            && $ip !== null
            && filter_var($ip, FILTER_VALIDATE_IP) !== false
            && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false;
    }
}
