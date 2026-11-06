<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ChatController extends Controller
{
    /**
     * Handle the SSO redirection handshake to XAMPP standalone Chat system.
     */
    public function openChat()
    {
        $user = Auth::user();

        if (!$user) {
            return redirect()->route('login');
        }

        // Messify subsystem check: ensure the messaging subsystem is active
        $messifyActive = \Illuminate\Support\Facades\DB::table(
            \Illuminate\Support\Facades\Schema::hasTable('sys_subsystems') ? 'sys_subsystems' : 'subsystems'
        )->where('subsystem_name', 'Messify')->where('is_active', true)->exists();

        if (!$messifyActive) {
            return redirect()->route('portal');
        }

        // Generate expiration timestamp: 60 seconds from now
        $expires = time() + 60;

        // Build payload
        $payload = $user->account_id . '|' . $expires;

        // Generate HMAC SHA256 token
        $secret = env('CHAT_SHARED_SECRET', '7f5b84c8a2bf6d91cd4a9c68aef2bc7e4c925d8864b85abef95a720cf12a32cd');
        $token = hash_hmac('sha256', $payload, $secret);

        // Redirect user to standalone chat system using dynamic host
        $host = request()->getSchemeAndHttpHost();
        $url = $host . '/messify/auth_entry.php?' . http_build_query([
            'account_id' => $user->account_id,
            'expires'    => $expires,
            'token'      => $token,
        ]);

        return redirect()->away($url);
    }
}