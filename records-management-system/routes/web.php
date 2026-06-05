<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

// Login
Volt::route('/', 'pages.portal.login')
    ->name('login');
Route::post('/', fn () => redirect()->route('login'));

// Public document tracking
Volt::route('/track-document', 'pages.portal.track-document')
    ->name('track-document');
Volt::route('/tracked', 'pages.portal.tracked')
    ->name('tracked');

// Secured routes (behind auth middleware)
Route::middleware(['auth'])
    ->group(function () {

    Volt::route('/portal', 'pages.portal.access-page')
        ->name('portal');
    Volt::route('/profile', 'pages.portal.profile')
        ->name('profile');

    Route::get('/dts', function () {
        return view('dts.index');
    })->name('dts');

});
Route::get('/url', function () {
    return redirect('/');
})->name('url');
// Logout
Route::get('/logout', function () {
    $user = Auth::user();
    if ($user && $user->details) {
        $user->details->update([
            'is_currently_online' => false,
            'last_online_time'    => now(),
        ]);
    }

    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('login');
})->name('logout');
