<?php

use Illuminate\Support\Facades\Route;

// Login
Route::livewire('/', 'pages::portal.login')->name('login');

// Public document tracking
Route::livewire('/track-document', 'pages::portal.track-document')->name('track-document');
Route::livewire('/tracked', 'pages::portal.tracked')->name('tracked');

// Authenticated portal
Route::livewire('/portal{userId}', 'pages::portal.access-page')->name('portal');
Route::livewire('/profile', 'pages::portal.profile')->name('profile');

// Logout — redirect until session auth is implemented
Route::get('/logout', function () {
    return redirect()->route('login');
})->name('logout');

// DTS (classic Blade for now)
Route::get('/dts', function () {
    return view('dts.dashboard');
})->name('dts');
