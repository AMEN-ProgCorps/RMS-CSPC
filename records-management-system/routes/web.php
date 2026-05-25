<?php

use Illuminate\Support\Facades\Route;

// Login
Route::livewire('/', 'pages::portal.login')
    ->name('login');

// Public document tracking
Route::livewire('/track-document', 'pages::portal.track-document')
    ->name('track-document');
Route::livewire('/tracked', 'pages::portal.tracked')
    ->name('tracked');

// 2. SECURED PORTAL ROUTES (Locked down behind the 'auth' firewall)
Route::middleware(['auth'])
    ->group(function () {
    
    Route::livewire('/portal', 'pages::portal.access-page')
        ->name('portal')
        ->middleware('auth');
    Route::livewire('/profile', 'pages::portal.profile')
        ->name('profile')
        ->middleware('auth');

    // Moved inside so only logged-in users can view the DTS dashboard
    Route::get('/dts', function () {
        return view('dts.dashboard');
    })->name('dts')
      ->middleware('auth');
    
});


// 3. LOGOUT ROUTE
Route::get('/logout', function () {
    // Clear the session cleanly when hit
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();
    
    return redirect()->route('login');
})->name('logout');
