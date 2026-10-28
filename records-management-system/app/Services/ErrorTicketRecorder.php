<?php

namespace App\Services;

use App\Models\ErrorTicket;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class ErrorTicketRecorder
{
    private const DEDUPE_HOURS = 6;

    /**
     * Persist an auto-captured server error. Never throws to the caller.
     */
    public static function recordAuto(array $payload): ?ErrorTicket
    {
        try {
            if (! Schema::hasTable('sys_error_tickets')) {
                return null;
            }

            $reference = (string) ($payload['reference'] ?? '');
            $fingerprint = self::fingerprint(
                (string) ($payload['exception_class'] ?? ''),
                (string) ($payload['file'] ?? ''),
                (int) ($payload['line'] ?? 0),
                (string) ($payload['message'] ?? '')
            );

            if ($reference !== '') {
                $existingByRef = ErrorTicket::query()->where('reference', $reference)->first();
                if ($existingByRef) {
                    return self::bump($existingByRef, $payload);
                }
            }

            $openMatch = ErrorTicket::query()
                ->where('fingerprint', $fingerprint)
                ->whereIn('status', ['open', 'in_progress'])
                ->where('last_seen_at', '>=', now()->subHours(self::DEDUPE_HOURS))
                ->orderByDesc('last_seen_at')
                ->first();

            if ($openMatch) {
                return self::bump($openMatch, $payload);
            }

            $title = trim((string) ($payload['title'] ?? 'Server error'));
            if ($title === '') {
                $title = 'Server error';
            }

            return ErrorTicket::query()->create([
                'ticket_number' => $reference !== '' ? $reference : self::nextTicketNumber('SRV'),
                'source' => 'auto',
                'reference' => $reference !== '' ? $reference : null,
                'fingerprint' => $fingerprint,
                'title' => Str::limit($title, 240),
                'description' => (string) ($payload['message'] ?? ''),
                'kind' => $payload['kind'] ?? 'server',
                'http_status' => $payload['http_status'] ?? 500,
                'url' => isset($payload['url']) ? Str::limit((string) $payload['url'], 2000, '') : null,
                'subsystem' => self::detectSubsystem($payload['url'] ?? null),
                'exception_class' => $payload['exception_class'] ?? null,
                'file' => $payload['file'] ?? null,
                'line' => $payload['line'] ?? null,
                'context' => $payload['context'] ?? null,
                'status' => 'open',
                'priority' => self::priorityFromStatus((int) ($payload['http_status'] ?? 500)),
                'occurrence_count' => 1,
                'last_seen_at' => now(),
                'reported_by' => Auth::id(),
            ]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Persist a user-submitted problem report.
     *
     * @throws \Illuminate\Validation\ValidationException|\RuntimeException
     */
    public static function recordUser(array $payload): ErrorTicket
    {
        if (! Schema::hasTable('sys_error_tickets')) {
            throw new \RuntimeException('Error ticketing is not available yet.');
        }

        $title = trim((string) ($payload['title'] ?? ''));
        $description = trim((string) ($payload['description'] ?? ''));
        $reference = trim((string) ($payload['reference'] ?? ''));
        $url = (string) ($payload['url'] ?? request()?->fullUrl() ?? '');

        if ($title === '') {
            $title = 'User-reported problem';
        }

        $fingerprint = self::fingerprint('user', $url, 0, $title.'|'.$description);

        return ErrorTicket::query()->create([
            'ticket_number' => self::nextTicketNumber('TKT'),
            'source' => 'user',
            'reference' => $reference !== '' ? Str::limit($reference, 32, '') : null,
            'fingerprint' => $fingerprint,
            'title' => Str::limit($title, 240),
            'description' => $description,
            'kind' => $payload['kind'] ?? 'user',
            'http_status' => $payload['http_status'] ?? null,
            'url' => $url !== '' ? Str::limit($url, 2000, '') : null,
            'subsystem' => self::detectSubsystem($url),
            'exception_class' => null,
            'file' => null,
            'line' => null,
            'context' => array_filter([
                'user_agent' => request()?->userAgent(),
                'ip' => request()?->ip(),
                'route' => optional(request()?->route())->getName(),
            ]),
            'status' => 'open',
            'priority' => $payload['priority'] ?? 'medium',
            'occurrence_count' => 1,
            'last_seen_at' => now(),
            'reported_by' => Auth::id(),
        ]);
    }

    public static function detectSubsystem(?string $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) ? strtolower($path) : strtolower($url);

        return match (true) {
            str_contains($path, '/admin') => 'admin',
            str_contains($path, '/dts') => 'dts',
            str_contains($path, '/rdp') => 'rdp',
            str_contains($path, '/dcs') => 'dcs',
            str_contains($path, '/portal') => 'portal',
            str_contains($path, '/profile') => 'profile',
            default => null,
        };
    }

    private static function bump(ErrorTicket $ticket, array $payload): ErrorTicket
    {
        $ticket->occurrence_count = (int) $ticket->occurrence_count + 1;
        $ticket->last_seen_at = now();

        if (! empty($payload['reference'])) {
            $ticket->reference = (string) $payload['reference'];
        }
        if (! empty($payload['url'])) {
            $ticket->url = Str::limit((string) $payload['url'], 2000, '');
        }
        if ($ticket->status === 'resolved' || $ticket->status === 'closed') {
            $ticket->status = 'open';
            $ticket->resolved_at = null;
        }

        $ticket->save();

        return $ticket;
    }

    private static function fingerprint(string $class, string $file, int $line, string $message): string
    {
        return hash('sha256', strtolower($class).'|'.strtolower($file).'|'.$line.'|'.md5(strtolower(trim($message))));
    }

    private static function nextTicketNumber(string $prefix): string
    {
        return strtoupper($prefix).'-'.strtoupper(Str::random(6));
    }

    private static function priorityFromStatus(int $status): string
    {
        if ($status >= 500) {
            return 'high';
        }
        if ($status === 429 || $status === 408) {
            return 'medium';
        }

        return 'low';
    }
}
