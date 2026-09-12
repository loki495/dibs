<?php

declare(strict_types=1);
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::livewire('/login', 'pages::auth.login')->middleware('guest')->name('login');
Route::livewire('/', 'pages::workspace')->middleware('auth')->name('workspace');
Route::livewire('/push-queue', 'pages::push-queue')->middleware('auth')->name('push-queue');
Route::post('/logout', function (Request $request): RedirectResponse {
    Auth::logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect()->route('login');
})->middleware('auth')->name('logout');
