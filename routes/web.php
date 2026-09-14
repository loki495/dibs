<?php

declare(strict_types=1);
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Icon files live under /app-icons, not /icons -- Apache ships a built-in `Alias /icons/`
// (mods-enabled/alias.conf, used for autoindex folder icons) that silently shadows any
// public/icons/ path with a 404, regardless of what's actually on disk there.
Route::get('/manifest.webmanifest', fn (): JsonResponse => response()->json([
    'name' => config('app.name'),
    'short_name' => config('app.name'),
    'start_url' => '/',
    'display' => 'standalone',
    'background_color' => '#0f172a',
    'theme_color' => '#115e59',
    'icons' => [
        ['src' => '/app-icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => '/app-icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
    ],
])->header('Content-Type', 'application/manifest+json'))->name('manifest');

Route::livewire('/login', 'pages::auth.login')->middleware('guest')->name('login');
Route::livewire('/', 'pages::workspace')->middleware('auth')->name('workspace');
Route::livewire('/push-queue', 'pages::push-queue')->middleware('auth')->name('push-queue');
Route::post('/logout', function (Request $request): RedirectResponse {
    Auth::logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect()->route('login');
})->middleware('auth')->name('logout');
