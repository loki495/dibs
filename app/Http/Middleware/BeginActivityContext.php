<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Activity\ActivityContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BeginActivityContext
{
    public function __construct(private readonly ActivityContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->beginWeb();

        return $next($request);
    }
}
