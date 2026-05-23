<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Login function
Route::get('/', function () {
    return view('portal.login.index');
})->name('login');

Route::post('/', function (Request $request) {
    // TODO: add real authentication logic here
    return redirect()->route('portal');
})->name('login.submit');

// Tracking document function
Route::get('/track-document', function () {
    return view('portal.tracking.td');
})->name('track-document');

Route::get('/tracked', function(){
    return view('portal.tracking.tracking');
})->name('tracked');

// Portal access Function
Route::get('/portal', function () {
    return view('portal.accesspage.option');
})->name('portal');
// Profile function
Route::get('/profile', function () {
    return view('portal.profile.page');
})->name('profile');
// Logout function
Route::get('/logout', function () {
    return redirect()->route('login');
})->name('logout');

// DTS function
Route::get('/dts', function () {
    return view('dts.dashboard');
})->name('dts');