<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generated URLs follow the scheme the proxy reports, and a proxy that terminates TLS in front of a
 * plain-http hop can report the wrong one, leaving http:// asset and Livewire URLs on a page the browser
 * loaded over https, which it then blocks as mixed content. The configured host has one canonical origin,
 * so use it for requests to that host and leave every other host on the scheme it was requested with.
 */
class ForceCanonicalUrl
{
    public function handle(Request $request, Closure $next): Response
    {
        $root = rtrim((string) config('app.url'), '/');
        $host = parse_url($root, PHP_URL_HOST);

        if (is_string($host) && $host !== '' && $request->getHost() === $host) {
            $scheme = parse_url($root, PHP_URL_SCHEME);

            if (is_string($scheme) && $scheme !== '') {
                URL::forceScheme($scheme);
            }

            URL::forceRootUrl($root);
        }

        return $next($request);
    }
}
