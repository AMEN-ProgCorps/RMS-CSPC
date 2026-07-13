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

        // Generate expiration timestamp: 60 seconds from now
        $expires = time() + 60;

        // Build payload
        $payload = $user->account_id . '|' . $expires;

        // Generate HMAC SHA256 token
        $token = hash_hmac('sha256', $payload, env('CHAT_SHARED_SECRET'));

        // Redirect user to standalone chat system using dynamic host
        $host = request()->getSchemeAndHttpHost();
        $url = $host . '/chatify/auth_entry.php?' . http_build_query([
            'account_id' => $user->account_id,
            'expires'    => $expires,
            'token'      => $token,
        ]);

        return redirect()->away($url);
    }
}