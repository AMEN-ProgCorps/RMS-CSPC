<?php

use App\Http\Controllers\ChatController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

// Login
Volt::route('/', 'pages.portal.login')
    ->name('login');
Route::post('/', fn () => redirect()->route('login'));

// Google OAuth SSO
// Helper closure to resolve SSO credentials from DB (Admin Console) → config → env
$resolveGoogleSsoCredentials = function () {
    $clientId = \Illuminate\Support\Facades\DB::table('system_settings')->where('key', 'google_sso_client_id')->value('value')
        ?: config('services.google.client_id')
        ?: env('GOOGLE_CLIENT_ID');

    $clientSecret = \Illuminate\Support\Facades\DB::table('system_settings')->where('key', 'google_sso_client_secret')->value('value')
        ?: config('services.google.client_secret')
        ?: env('GOOGLE_CLIENT_SECRET');

    $dbRedirect = \Illuminate\Support\Facades\DB::table('system_settings')->where('key', 'google_sso_redirect_uri')->value('value');
    $envRedirect = $dbRedirect ?: env('GOOGLE_REDIRECT_URI');
    $redirectUrl = (!empty($envRedirect) && $envRedirect !== 'dynamic')
        ? $envRedirect
        : url('/auth/google/callback');

    return compact('clientId', 'clientSecret', 'redirectUrl');
};

Route::get('/auth/google', function () use ($resolveGoogleSsoCredentials) {
    $allowGoogleLogin = \Illuminate\Support\Facades\DB::table('system_settings')->where('key', 'allow_google_login')->value('value') !== 'false';
    if (!$allowGoogleLogin) {
        return redirect('/')->with('error', 'Google Sign-In is currently disabled by administrator.');
    }

    ['clientId' => $clientId, 'clientSecret' => $clientSecret, 'redirectUrl' => $redirectUrl] = $resolveGoogleSsoCredentials();

    if (empty($clientId) || empty($clientSecret)) {
        return redirect('/')->with('error', 'Google Auth credentials (GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET) are missing. Configure them in Admin Console → Settings.');
    }

    return \Laravel\Socialite\Facades\Socialite::buildProvider(
        \Laravel\Socialite\Two\GoogleProvider::class,
        [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'redirect'      => $redirectUrl,
        ]
    )->stateless()->redirect();
})->name('auth.google');

Route::get('/auth/google/callback', function () use ($resolveGoogleSsoCredentials) {
    try {
        ['clientId' => $clientId, 'clientSecret' => $clientSecret, 'redirectUrl' => $redirectUrl] = $resolveGoogleSsoCredentials();

        $googleUser = \Laravel\Socialite\Facades\Socialite::buildProvider(
            \Laravel\Socialite\Two\GoogleProvider::class,
            [
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'redirect'      => $redirectUrl,
            ]
        )->stateless()->user();
        $email = strtolower(trim($googleUser->getEmail()));

        // Lookup account in account_details by email
        $accountDetail = \Illuminate\Support\Facades\DB::table('account_details')->whereRaw('LOWER(email) = ?', [$email])->first();

        if (!$accountDetail) {
            \Illuminate\Support\Facades\DB::table('security_logs')->insert([
                'status'      => 2, // Failed Login
                'account'     => null,
                'user_ipaddr' => \App\Helpers\NetworkHelper::getClientIp(),
                'time'        => now(),
            ]);

            return redirect('/')->with('error', "No registered RMS account found for '{$email}'. Please contact your administrator.");
        }

        // Verify account is active
        $account = \Illuminate\Support\Facades\DB::table('account')->where('id', $accountDetail->account_id)->first();
        if (!$account || !$account->account_active) {
            \Illuminate\Support\Facades\DB::table('security_logs')->insert([
                'status'      => 2, // Failed Login
                'account'     => $accountDetail->account_id,
                'user_ipaddr' => \App\Helpers\NetworkHelper::getClientIp(),
                'time'        => now(),
            ]);

            return redirect('/')->with('error', 'Your account is deactivated. Please contact your administrator.');
        }

        // Authenticate user
        Auth::loginUsingId($accountDetail->account_id);
        session()->regenerate();

        // Log successful login
        \Illuminate\Support\Facades\DB::table('security_logs')->insert([
            'status'      => 1, // Login Successful
            'account'     => $accountDetail->account_id,
            'user_ipaddr' => \App\Helpers\NetworkHelper::getClientIp(),
            'time'        => now(),
        ]);

        \Illuminate\Support\Facades\DB::table('account_details')
            ->where('account_id', $accountDetail->account_id)
            ->update([
                'is_currently_online' => true,
                'last_online_time'    => now(),
            ]);

        return redirect('/portal');
    } catch (\Throwable $e) {
        $msg = $e->getMessage() ?: get_class($e);
        \Illuminate\Support\Facades\Log::error('Google Auth Error: ' . $msg . "\n" . $e->getTraceAsString());
        return redirect('/')->with('error', 'Google authentication failed: ' . $msg);
    }
})->name('auth.google.callback');

// Public document tracking
Volt::route('/track-document', 'pages.portal.track-document')
    ->name('track-document');
Volt::route('/tracked', 'pages.portal.tracked')
    ->name('tracked');

// Secured routes (behind auth middleware)
Route::middleware(['auth'])
    ->group(function () {
    Route::get('/open-chat', [ChatController::class, 'openChat'])->name('open-chat');
    Route::get('/chat/unread-count', function () {
        $userId = Auth::id();
        if (!$userId) {
            return response()->json(['unread' => 0]);
        }
        try {
            $totalUnread = \Illuminate\Support\Facades\DB::table('view_user_unread_chats')
                ->where('account_id', $userId)
                ->value('total_unread');
            return response()->json(['unread' => (int) ($totalUnread ?? 0)]);
        } catch (\Throwable $e) {
            return response()->json(['unread' => 0]);
        }
    })->name('chat.unread-count');


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
        Volt::route('/admin/activity/file-uploads', 'pages.admin.activity.file-uploads')->name('admin.activity.file-uploads');
        Volt::route('/admin/activity/notifications', 'pages.admin.activity.notifications')->name('admin.activity.notifications');
        Volt::route('/admin/activity/dts/transaction-logs', 'pages.admin.activity.dts.transaction-logs')->name('admin.activity.dts.transaction-logs');
        Volt::route('/admin/activity/dts/update-logs', 'pages.admin.activity.dts.update-logs')->name('admin.activity.dts.update-logs');
        Volt::route('/admin/activity/dts/flow-logs', 'pages.admin.activity.dts.flow-logs')->name('admin.activity.dts.flow-logs');
        Volt::route('/admin/activity/chat-audit', 'pages.admin.activity.chat-audit')->name('admin.activity.chat-audit');


        Volt::route('/admin/dts/action-options', 'pages.admin.dts.action-options')->name('admin.dts.action-options');
        Volt::route('/admin/dts/transaction-flows', 'pages.admin.dts.transaction-flows')->name('admin.dts.transaction-flows');

        Route::redirect('/admin/rdp', '/admin/rdp/records-logs')->name('admin.rdp.index');
        Volt::route('/admin/rdp/records-logs', 'pages.admin.rdp.records-logs')->name('admin.rdp.records-logs');
        Volt::route('/admin/rdp/volume-conversion-logs', 'pages.admin.rdp.update-logs')->name('admin.rdp.volume-conversion-logs');
        Volt::route('/admin/rdp/update-logs', 'pages.admin.rdp.update-logs')->name('admin.rdp.update-logs');
        Volt::route('/admin/rdp/record-series-logs', 'pages.admin.rdp.record-series-logs')->name('admin.rdp.record-series-logs');
        Volt::route('/admin/rdp/conversions', 'pages.admin.rdp.conversions')->name('admin.rdp.conversions');
        Volt::route('/admin/rdp/record-series', 'pages.admin.rdp.record-series')->name('admin.rdp.record-series');

        Volt::route('/admin/subsystems/add', 'pages.admin.subsystems.add')->name('admin.subsystems.add');
        Volt::route('/admin/subsystems/activate', 'pages.admin.subsystems.activate')->name('admin.subsystems.activate');
        Volt::route('/admin/subsystems/deactivate', 'pages.admin.subsystems.deactivate')->name('admin.subsystems.deactivate');
        Volt::route('/admin/subsystems/changes-logs', 'pages.admin.subsystems.changes-logs')->name('admin.subsystems.changes-logs');
        Volt::route('/admin/settings', 'pages.admin.settings.index')->name('admin.settings.index');
        Volt::route('/admin/recycle-bin', 'pages.admin.recycle-bin')->name('admin.recycle-bin');
    });

    // RDP — Records Disposition Program (requires can_access_rdp or is_sadm)
    Route::middleware(['can.access.rdp'])->group(function () {
        Volt::route('/rdp', 'pages.rdp.index')->name('rdp');
        Volt::route('/rdp/manage-files', 'pages.rdp.manage-files')->name('rdp.manage-files');

        Volt::route('/rdp/add-records/inventory-and-appraisal', 'pages.rdp.add-records.inventory-and-appraisal')->name('rdp.add-records.inventory-and-appraisal');
        Volt::route('/rdp/add-records/records-and-disposition-schedule', 'pages.rdp.add-records.records-and-disposition-schedule')->name('rdp.add-records.records-and-disposition-schedule');

        Volt::route('/rdp/reports/nap-form-1', 'pages.rdp.reports.nap-form-1')->name('rdp.reports.nap-form-1');
        Volt::route('/rdp/reports/nap-form-2', 'pages.rdp.reports.nap-form-2')->name('rdp.reports.nap-form-2');
        Volt::route('/rdp/reports/nap-form-3', 'pages.rdp.reports.nap-form-3')->name('rdp.reports.nap-form-3');

        Volt::route('/rdp/references/{type}', 'pages.rdp.references.show')->name('rdp.references.show');

        // Pending Subsystem
        Volt::route('/rdp/pending/list', 'pages.rdp.pending.list')->name('rdp.pending.list');
        Volt::route('/rdp/pending/for-approval', 'pages.rdp.pending.for-approval')->name('rdp.pending.for-approval');

        // Draft Subsystem
        Volt::route('/rdp/draft/inventory-and-appraisal', 'pages.rdp.draft.inventory-and-appraisal')->name('rdp.draft.inventory-and-appraisal');
        Volt::route('/rdp/draft/records-and-disposition-schedule', 'pages.rdp.draft.records-and-disposition-schedule')->name('rdp.draft.records-and-disposition-schedule');
    });

    // DTS — Document Tracking System (requires can_access_dts or is_sadm)
    Route::middleware(['can.access.dts'])->group(function () {
        Volt::route('/dts', 'pages.dts.index')->name('dts');
        Volt::route('/dts/receive', 'pages.dts.receive')->name('dts.receive');
        Route::get('/dts/scanner', fn () => redirect()->route('dts.receive'))->name('dts.scanner');
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

        Route::get('/dts/view-document', function (\Illuminate\Http\Request $request) {
            $path = $request->query('path');
            if (!$path) {
                abort(404);
            }

            $content = \App\Services\DocumentStorageService::getFileContent($path);
            if (!$content) {
                abort(404, 'Document file not found.');
            }

            $filename = basename($path);
            return response($content, 200)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'inline; filename="' . $filename . '"');
        })->name('dts.view-document');
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
            'user_ipaddr' => \App\Helpers\NetworkHelper::getClientIp(),
            'time'        => now(),
        ]);

        \Illuminate\Support\Facades\DB::table('account_details')
            ->where('account_id', $user->id)
            ->update([
                'is_currently_online' => false,
                'last_online_time'    => now(),
            ]);
            // Invalidate the user's chat session in the XAMPP chat system
        try {
            \Illuminate\Support\Facades\Http::timeout(3)->post(url('/chatify/invalidate_chat_session.php'), [
                'account_id' => $user->id,
                'secret'     => env('CHAT_SHARED_SECRET', ''),
            ]);
        } catch (\Exception $e) {
            // Non-fatal — chat session will expire naturally
        }
    }

    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('login');
})->name('logout');

// Global Fallback — Redirects any unmatched URL/404 directly to portal page
Route::fallback(function () {
    return redirect()->route('portal');
});
