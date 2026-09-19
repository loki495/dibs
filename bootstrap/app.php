<?php

use App\Http\Middleware\BeginActivityContext;
use App\Http\Middleware\ResolveDemoDatabase;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Demo mode only (config('dibs.demo_mode')) -- no-op otherwise. Must run after
        // EncryptCookies (it reads the visitor-id cookie -- a bare prependToGroup runs before
        // EncryptCookies decrypts anything, so the cookie never validates and a fresh visitor
        // id gets minted every single request) and before StartSession (see
        // ResolveDemoDatabase's own docblock). appendToGroup only fixes the array position;
        // the priority list is what Laravel's router actually sorts by.
        $middleware->appendToGroup('web', ResolveDemoDatabase::class);
        $middleware->appendToGroup('web', BeginActivityContext::class);
        $middleware->prependToPriorityList(before: StartSession::class, prepend: ResolveDemoDatabase::class);
        $middleware->appendToPriorityList(after: EncryptCookies::class, append: ResolveDemoDatabase::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
