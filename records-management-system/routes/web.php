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

    // DTS — Document Tracking System (requires can_access_dts or is_sadm)
    Route::middleware(['can.access.dts'])->group(function () {
        Volt::route('/dts', 'pages.dts.index')->name('dts');
        Volt::route('/dts/receive', 'pages.dts.receive')->name('dts.receive');

        Volt::route('/dts/create/internal', 'pages.dts.create.internal')->name('dts.create.internal');
        Volt::route('/dts/create/external', 'pages.dts.create.external')->name('dts.create.external');
        Volt::route('/dts/create/application-letters', 'pages.dts.create.application-letters')->name('dts.create.application-letters');
        Volt::route('/dts/create/issuances', 'pages.dts.create.issuances')->name('dts.create.issuances');

        Volt::route('/dts/list/internal', 'pages.dts.list.internal')->name('dts.list.internal');
        Volt::route('/dts/list/external', 'pages.dts.list.external')->name('dts.list.external');
        Volt::route('/dts/list/application-letters', 'pages.dts.list.application-letters')->name('dts.list.application-letters');
        Volt::route('/dts/list/issuances', 'pages.dts.list.issuances')->name('dts.list.issuances');
    });

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
