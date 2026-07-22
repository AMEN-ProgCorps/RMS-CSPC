<?php

namespace App\Helpers;

class NetworkHelper
{
    /**
     * Get the real client IP address, properly resolving proxies,
     * Tailscale tunnels, Cloudflare, Nginx, and Docker bridges.
     */
    public static function getClientIp(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR',
        ];

        // 1. Check for public IP addresses in forwarded headers
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                foreach (explode(',', $_SERVER[$header]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }

        // 2. Check for private/tunneled IPs (e.g. Tailscale 100.x.x.x CGNAT or LAN IPs) from forwarded headers
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                foreach (explode(',', $_SERVER[$header]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP) !== false && $ip !== '127.0.0.1' && $ip !== '::1') {
                        return $ip;
                    }
                }
            }
        }

        // 3. Fallback to Laravel's request IP
        return request()->ip() ?: '127.0.0.1';
    }
}
