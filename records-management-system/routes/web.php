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

    // Profile Manager
    Volt::route('/profile', 'pages.profile.index')->name('profile');
    Volt::route('/profile/security-logs', 'pages.profile.security-logs')->name('profile.security-logs');
    Volt::route('/profile/notification-manager', 'pages.profile.notification-manager')->name('profile.notification-manager');

    // Admin Console (requires is_sadm)
    Route::middleware(['can.access.admin'])->group(function () {
        Volt::route('/admin/console', 'pages.admin.console.index')->name('admin.console');

        Volt::route('/admin/accounts/users', 'pages.admin.accounts.users')->name('admin.accounts.users');
        Volt::route('/admin/accounts/roles', 'pages.admin.accounts.roles')->name('admin.accounts.roles');
        Volt::route('/admin/accounts/offices', 'pages.admin.accounts.offices')->name('admin.accounts.offices');

        Volt::route('/admin/activity/logins', 'pages.admin.activity.logins')->name('admin.activity.logins');
        Volt::route('/admin/activity/account-changes', 'pages.admin.activity.account-changes')->name('admin.activity.account-changes');
        Volt::route('/admin/activity/notifications', 'pages.admin.activity.notifications')->name('admin.activity.notifications');

        Volt::route('/admin/rms/transaction-logs', 'pages.admin.rms.transaction-logs')->name('admin.rms.transaction-logs');
        Volt::route('/admin/rms/update-logs', 'pages.admin.rms.update-logs')->name('admin.rms.update-logs');

        Volt::route('/admin/rdp/records-logs', 'pages.admin.rdp.records-logs')->name('admin.rdp.records-logs');
        Volt::route('/admin/rdp/update-logs', 'pages.admin.rdp.update-logs')->name('admin.rdp.update-logs');

        Volt::route('/admin/subsystems/add', 'pages.admin.subsystems.add')->name('admin.subsystems.add');
        Volt::route('/admin/subsystems/activate', 'pages.admin.subsystems.activate')->name('admin.subsystems.activate');
        Volt::route('/admin/subsystems/deactivate', 'pages.admin.subsystems.deactivate')->name('admin.subsystems.deactivate');
        Volt::route('/admin/subsystems/changes-logs', 'pages.admin.subsystems.changes-logs')->name('admin.subsystems.changes-logs');
    });

    // RDP — Records Disposition Program (requires can_access_rdp or is_sadm)
    Route::middleware(['can.access.rdp'])->group(function () {
        Volt::route('/rdp', 'pages.rdp.index')->name('rdp');

        Volt::route('/rdp/add-records/inventory-and-appraisal', 'pages.rdp.add-records.inventory-and-appraisal')->name('rdp.add-records.inventory-and-appraisal');
        Volt::route('/rdp/add-records/records-and-disposition-schedule', 'pages.rdp.add-records.records-and-disposition-schedule')->name('rdp.add-records.records-and-disposition-schedule');

        Volt::route('/rdp/reports/nap-form-1', 'pages.rdp.reports.nap-form-1')->name('rdp.reports.nap-form-1');
        Volt::route('/rdp/reports/nap-form-2', 'pages.rdp.reports.nap-form-2')->name('rdp.reports.nap-form-2');
        Volt::route('/rdp/reports/nap-form-3', 'pages.rdp.reports.nap-form-3')->name('rdp.reports.nap-form-3');
    });

    // DTS — Document Tracking System (requires can_access_dts or is_sadm)
    Route::middleware(['can.access.dts'])->group(function () {
        Volt::route('/dts', 'pages.dts.index')->name('dts');
        Volt::route('/dts/receive', 'pages.dts.receive')->name('dts.receive');
        // Sub-filter pages merged into index; these redirect to keep old links working
        Route::get('/dts/internal', fn () => redirect()->route('dts'))->name('dts.internal');
        Route::get('/dts/external', fn () => redirect()->route('dts'))->name('dts.external');
        Route::get('/dts/applications', fn () => redirect()->route('dts'))->name('dts.applications');
        Route::get('/dts/issuances', fn () => redirect()->route('dts'))->name('dts.issuances');

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
    if ($user) {
        // Log Logout
        \Illuminate\Support\Facades\DB::table('security_logs')->insert([
            'status'      => 3, // Logout
            'account'     => $user->id,
            'user_ipaddr' => request()->ip(),
            'time'        => now(),
        ]);

        \Illuminate\Support\Facades\DB::table('account_details')
            ->where('account_id', $user->id)
            ->update([
                'is_currently_online' => false,
                'last_online_time'    => now(),
            ]);
    }

    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('login');
})->name('logout');
