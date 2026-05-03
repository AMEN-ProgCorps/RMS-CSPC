<?php

use Illuminate\Support\Facades\Route;

// Login function
Route::get('/', function () {
    return view('portal.login.index');
})->name('login');

// Tracking document function
Route::get('/track-document', function () {
    return view('portal.tracking.td');
})->name('track-document');

// Portal access Function
Route::get('/portal', function () {
    return view('portal.accesspage.option');
})->name('portal');

// Logout function
Route::get('/logout', function () {
    return redirect()->route('login');
})->name('logout');

// DTS function
Route::get('/dts', function () {
    return view('dts.dashboard');
})->name('dts');