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
                $existingByRef = ErrorTicket::query()
                    ->where('reference', $reference)
                    ->orWhere('ticket_number', $reference)
                    ->first();
                if ($existingByRef) {
                    return self::bump($existingByRef, $payload, isAuto: true);
                }
            }

            $openMatch = ErrorTicket::query()
                ->where('fingerprint', $fingerprint)
                ->whereIn('status', ['open', 'in_progress', 'notice'])
                ->where('last_seen_at', '>=', now()->subHours(self::DEDUPE_HOURS))
                ->orderByDesc('last_seen_at')
                ->first();

            if ($openMatch) {
                return self::bump($openMatch, $payload, isAuto: true);
            }

            $title = trim((string) ($payload['title'] ?? 'Server error'));
            if ($title === '') {
                $title = 'Server error';
            }

            $isNotice = self::isSlowInternet($payload);

            $created = ErrorTicket::query()->create([
                'ticket_number' => $reference !== '' ? $reference : self::nextTicketNumber('SRV'),
                'source' => ErrorTicket::SOURCE_AUTO,
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
                'status' => $isNotice ? 'notice' : 'open',
                'priority' => $isNotice ? 'low' : self::priorityFromStatus((int) ($payload['http_status'] ?? 500)),
                'occurrence_count' => 1,
                'last_seen_at' => now(),
                'reported_by' => Auth::id(),
            ]);

            self::clearGuardsCache();

            return $created;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Persist a user-submitted problem report.
     * Merges with existing auto tickets into 'user_auto' (User Added | Auto) when matched.
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

        $reporterName = Auth::user()?->username ?? 'User';

        // Case 1: Reference provided matches an existing ticket (e.g. from an error popup or page reference)
        if ($reference !== '') {
            $existing = ErrorTicket::query()
                ->where('reference', $reference)
                ->orWhere('ticket_number', $reference)
                ->first();

            if ($existing) {
                // If it was auto-detected or existing, it becomes User Added | Auto
                $existing->source = ErrorTicket::SOURCE_USER_AUTO;
                if ($description !== '') {
                    $userNote = "[{$reporterName} Report]: {$description}";
                    $existing->description = $existing->description !== ''
                        ? "{$userNote}\n\n--- Diagnostic Stack & Details ---\n{$existing->description}"
                        : $userNote;
                }
                if ($title !== '' && ! in_array(strtolower($title), ['server error', 'client error', 'problem on this page', 'slow internet'], true)) {
                    $existing->title = Str::limit($title, 240);
                }
                if (Auth::check()) {
                    $existing->reported_by = Auth::id();
                }
                $isSlowInternet = self::isSlowInternet($payload)
                    || self::isSlowInternet(['title' => $existing->title, 'description' => $existing->description, 'http_status' => $existing->http_status]);

                if ($isSlowInternet) {
                    $existing->status = 'notice';
                    $existing->priority = 'low';
                } elseif ($payloadPriority = ($payload['priority'] ?? null)) {
                    $existing->priority = $payloadPriority;
                }
                if (! $isSlowInternet && in_array($existing->status, ['resolved', 'closed'], true)) {
                    $existing->status = 'open';
                    $existing->resolved_at = null;
                }
                $existing->occurrence_count = (int) $existing->occurrence_count + 1;
                $existing->last_seen_at = now();
                $existing->save();

                return $existing;
            }
        }

        // Case 2: Matching recent auto ticket on the same path within the last 15 minutes
        if ($url !== '') {
            $pathOnly = parse_url($url, PHP_URL_PATH);
            if (is_string($pathOnly) && $pathOnly !== '' && $pathOnly !== '/') {
                $recentAuto = ErrorTicket::query()
                    ->where('source', ErrorTicket::SOURCE_AUTO)
                    ->whereIn('status', ['open', 'in_progress', 'notice'])
                    ->where('url', 'like', "%{$pathOnly}%")
                    ->where('last_seen_at', '>=', now()->subMinutes(15))
                    ->orderByDesc('last_seen_at')
                    ->first();

                if ($recentAuto) {
                    $recentAuto->source = ErrorTicket::SOURCE_USER_AUTO;
                    if ($description !== '') {
                        $userNote = "[{$reporterName} Report]: {$description}";
                        $recentAuto->description = $recentAuto->description !== ''
                            ? "{$userNote}\n\n--- Diagnostic Stack & Details ---\n{$recentAuto->description}"
                            : $userNote;
                    }
                    if ($title !== '' && ! in_array(strtolower($title), ['server error', 'client error', 'problem on this page', 'slow internet'], true)) {
                        $recentAuto->title = Str::limit($title, 240);
                    }
                    if (Auth::check()) {
                        $recentAuto->reported_by = Auth::id();
                    }

                    if (self::isSlowInternet($payload) || self::isSlowInternet(['title' => $recentAuto->title, 'description' => $recentAuto->description, 'http_status' => $recentAuto->http_status])) {
                        $recentAuto->status = 'notice';
                        $recentAuto->priority = 'low';
                    }

                    $recentAuto->save();

                    return $recentAuto;
                }
            }
        }

        // Case 3: Pure user-submitted ticket (no auto-detection match) -> User Added
        $fingerprint = self::fingerprint('user', $url, 0, $title.'|'.$description);
        $isUserNotice = self::isSlowInternet($payload);

        return ErrorTicket::query()->create([
            'ticket_number' => self::nextTicketNumber('TKT'),
            'source' => ErrorTicket::SOURCE_USER,
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
            'status' => $isUserNotice ? 'notice' : 'open',
            'priority' => $isUserNotice ? 'low' : ($payload['priority'] ?? 'medium'),
            'occurrence_count' => 1,
            'last_seen_at' => now(),
            'reported_by' => Auth::id(),
        ]);
    }

    public static function isSlowInternet(array $payload): bool
    {
        $title = strtolower((string) ($payload['title'] ?? ''));
        $desc = strtolower((string) ($payload['message'] ?? $payload['description'] ?? ''));
        $httpStatus = (int) ($payload['http_status'] ?? 0);
        $exception = strtolower((string) ($payload['exception_class'] ?? ''));

        if (in_array($httpStatus, [408, 504], true)) {
            return true;
        }

        if (str_contains($title, 'slow internet') || str_contains($title, 'slow connection') || str_contains($title, 'connection is too slow')) {
            return true;
        }

        if (str_contains($desc, 'slow internet') || str_contains($desc, 'connection is too slow') || str_contains($desc, 'internet connection is too slow') || str_contains($desc, 'operation timed out')) {
            return true;
        }

        if (str_contains($exception, 'timeout') || str_contains($exception, 'connectionexception')) {
            return true;
        }

        return false;
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

    private static function bump(ErrorTicket $ticket, array $payload, bool $isAuto = false): ErrorTicket
    {
        $ticket->occurrence_count = (int) $ticket->occurrence_count + 1;
        $ticket->last_seen_at = now();

        if ($isAuto && $ticket->source === ErrorTicket::SOURCE_USER) {
            // Error was originally logged by user, now also caught by automated catcher!
            $ticket->source = ErrorTicket::SOURCE_USER_AUTO;
        }

        if (! empty($payload['reference'])) {
            $ticket->reference = (string) $payload['reference'];
        }
        if (! empty($payload['url']) && empty($ticket->url)) {
            $ticket->url = Str::limit((string) $payload['url'], 2000, '');
        }
        if (! empty($payload['exception_class']) && empty($ticket->exception_class)) {
            $ticket->exception_class = $payload['exception_class'];
        }
        if (! empty($payload['file']) && empty($ticket->file)) {
            $ticket->file = $payload['file'];
        }
        if (! empty($payload['line']) && empty($ticket->line)) {
            $ticket->line = $payload['line'];
        }
        if (! empty($payload['context'])) {
            $existing = (array) ($ticket->context ?? []);
            $ticket->context = array_merge($existing, (array) $payload['context']);
        }
        if (self::isSlowInternet($payload) || self::isSlowInternet(['title' => $ticket->title, 'description' => $ticket->description, 'http_status' => $ticket->http_status])) {
            $ticket->status = 'notice';
            $ticket->priority = 'low';
        } elseif ($ticket->status === 'resolved' || $ticket->status === 'closed') {
            $ticket->status = 'open';
            $ticket->resolved_at = null;
        }

        $ticket->save();
        self::clearGuardsCache();

        return $ticket;
    }

    private static function fingerprint(string $class, string $file, int $line, string $message): string
    {
        return hash('sha256', strtolower($class).'|'.strtolower($file).'|'.$line.'|'.md5(strtolower(trim($message))));
    }

    public static function clearGuardsCache(): void
    {
        try {
            \Illuminate\Support\Facades\Cache::forget('rms_active_error_guards');
        } catch (\Throwable) {}
    }

    /**
     * Consolidate historical open/in-progress tickets for the same page/fingerprint into unified threads.
     * Preserves total occurrences, updates last_seen_at, and retains historical reference codes.
     */
    public static function consolidateHistoricalDuplicates(): int
    {
        if (! Schema::hasTable('sys_error_tickets')) {
            return 0;
        }

        try {
            $tickets = ErrorTicket::query()
                ->whereIn('status', [ErrorTicket::STATUS_OPEN, ErrorTicket::STATUS_IN_PROGRESS, ErrorTicket::STATUS_NOTICE])
                ->orderBy('created_at', 'asc')
                ->get();

            if ($tickets->isEmpty()) {
                return 0;
            }

            $groups = $tickets->groupBy(function ($t) {
                if ($t->url) {
                    $path = parse_url($t->url, PHP_URL_PATH);
                    if ($path && $path !== '/' && ! str_contains($path, '/api/')) {
                        return 'path:'.strtolower(rtrim($path, '/'));
                    }
                }

                return 'fp:'.($t->fingerprint ?: $t->title);
            });

            $mergedCount = 0;

            foreach ($groups as $key => $items) {
                if ($items->count() <= 1) {
                    continue;
                }

                // Master ticket is the latest ticket
                /** @var ErrorTicket $master */
                $master = $items->last();
                $others = $items->slice(0, $items->count() - 1);

                $totalOccurrences = (int) $items->sum('occurrence_count');
                $maxLastSeen = $items->max('last_seen_at') ?? now();
                $hasUserReport = $items->contains(fn ($t) => in_array($t->source, [ErrorTicket::SOURCE_USER, ErrorTicket::SOURCE_USER_AUTO], true));

                $master->occurrence_count = max($totalOccurrences, (int) $master->occurrence_count);
                $master->last_seen_at = $maxLastSeen;

                if ($hasUserReport && $master->source === ErrorTicket::SOURCE_AUTO) {
                    $master->source = ErrorTicket::SOURCE_USER_AUTO;
                }

                $allRefs = $items->pluck('reference')->filter()->unique()->values()->all();
                if (! empty($allRefs)) {
                    $ctx = is_array($master->context) ? $master->context : [];
                    $ctx['historical_references'] = $allRefs;
                    $master->context = $ctx;
                }

                $master->save();

                ErrorTicket::query()->whereIn('id', $others->pluck('id'))->delete();
                $mergedCount += $others->count();
            }

            if ($mergedCount > 0) {
                self::clearGuardsCache();
            }

            return $mergedCount;
        } catch (\Throwable) {
            return 0;
        }
    }

    public static function getActiveGuards(): array
    {
        try {
            if (! Schema::hasTable('sys_error_tickets')) {
                return ['pages' => [], 'modals' => []];
            }

            return \Illuminate\Support\Facades\Cache::remember('rms_active_error_guards', 10, function () {
                $tickets = ErrorTicket::query()
                    ->whereIn('source', [ErrorTicket::SOURCE_AUTO, ErrorTicket::SOURCE_USER_AUTO])
                    ->whereIn('status', [ErrorTicket::STATUS_OPEN, ErrorTicket::STATUS_IN_PROGRESS])
                    ->select('id', 'ticket_number', 'url', 'title', 'subsystem', 'kind', 'reference', 'context', 'last_seen_at')
                    ->get();

                $pages = [];
                $modals = [];

                foreach ($tickets as $t) {
                    if ($t->url) {
                        $path = parse_url($t->url, PHP_URL_PATH);
                        if ($path && $path !== '/' && !str_contains($path, '/api/')) {
                            $pages[strtolower(rtrim($path, '/'))] = [
                                'ticket' => $t->ticket_number,
                                'title' => $t->title,
                                'ref' => $t->reference,
                                'subsystem' => $t->subsystem,
                                'url' => $t->url,
                            ];
                        }
                    }
                    if (!empty($t->kind) && (str_starts_with($t->kind, 'modal:') || str_starts_with($t->kind, 'modal_'))) {
                        $clean = strtolower(preg_replace('/^modal[:_]/', '', $t->kind));
                        $info = [
                            'ticket' => $t->ticket_number,
                            'title' => $t->title,
                            'ref' => $t->reference,
                        ];
                        $modals[strtolower($t->kind)] = $info;
                        $modals[$clean] = $info;
                    }
                    if (is_array($t->context)) {
                        if (!empty($t->context['component'])) {
                            $cKey = strtolower((string) $t->context['component']);
                            $modals[$cKey] = [
                                'ticket' => $t->ticket_number,
                                'title' => $t->title,
                                'ref' => $t->reference,
                            ];
                        }
                        if (!empty($t->context['modal'])) {
                            $mKey = strtolower((string) $t->context['modal']);
                            $modals[$mKey] = [
                                'ticket' => $t->ticket_number,
                                'title' => $t->title,
                                'ref' => $t->reference,
                            ];
                        }
                    }
                }

                return ['pages' => $pages, 'modals' => $modals];
            });
        } catch (\Throwable) {
            return ['pages' => [], 'modals' => []];
        }
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
