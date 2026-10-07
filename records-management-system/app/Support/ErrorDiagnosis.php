<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * One error shape for every subsystem.
 * Call {@see forStatus()} or {@see from()} and return {@see toArray()} or {@see toResponse()}.
 */
class ErrorDiagnosis
{
    public function __construct(
        public string $kind,
        public int $status,
        public string $title,
        public string $message,
        public ?string $reference = null,
    ) {}

    public static function from(Throwable $e): self
    {
        $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
        if ($status < 400) {
            $status = 500;
        }

        $specific = self::specificMessage($e);
        $raw = trim($e->getMessage());
        $message = $specific ?? (self::isTechnical($raw) || $raw === '' ? null : $raw);
        $diagnosed = self::forStatus($status, $message);

        if ($diagnosed->kind !== 'server') {
            return $diagnosed;
        }

        $reference = 'SRV-'.strtoupper(Str::random(6));
        try {
            Log::error('Server error '.$reference, [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'url' => request()?->fullUrl(),
                'user_id' => Auth::id(),
            ]);
        } catch (Throwable) {
            // A locked log file must not replace the original error.
        }

        return new self(
            $diagnosed->kind,
            $diagnosed->status,
            $diagnosed->title,
            $diagnosed->message,
            $reference,
        );
    }

    public static function forStatus(int $status, ?string $message = null): self
    {
        if ($status < 400) {
            $status = 500;
        }

        $entry = self::catalog()[$status] ?? (
            $status >= 500
                ? ['kind' => 'server', 'title' => 'Server error', 'message' => 'The server could not finish this request.']
                : ['kind' => 'client', 'title' => 'Client error', 'message' => 'This request could not be completed.']
        );

        $text = trim((string) $message);

        return new self(
            $entry['kind'],
            $status,
            $entry['title'],
            ($text !== '' && ! self::isTechnical($text)) ? $text : $entry['message'],
        );
    }

    /**
     * @return array<int, array{kind: string, title: string, message: string}>
     */
    public static function catalog(): array
    {
        return [
            401 => ['kind' => 'client', 'title' => 'Sign in required', 'message' => 'Sign in again before continuing.'],
            403 => ['kind' => 'client', 'title' => 'Access denied', 'message' => 'You do not have access to do that.'],
            404 => ['kind' => 'client', 'title' => 'Not found', 'message' => 'That record could not be found.'],
            405 => ['kind' => 'client', 'title' => 'Not allowed', 'message' => 'That action is not available.'],
            408 => ['kind' => 'client', 'title' => 'Timed out', 'message' => 'The connection timed out.'],
            413 => ['kind' => 'client', 'title' => 'File too large', 'message' => 'The uploaded file is too large.'],
            419 => ['kind' => 'client', 'title' => 'Session expired', 'message' => 'Your session expired. Refresh the page and try again.'],
            422 => ['kind' => 'client', 'title' => 'Check the form', 'message' => 'Some of the information needs to be corrected before this can continue.'],
            429 => ['kind' => 'client', 'title' => 'Too many requests', 'message' => 'Too many attempts. Wait a moment and try again.'],
            500 => ['kind' => 'server', 'title' => 'Server error', 'message' => 'The server could not finish this request.'],
            502 => ['kind' => 'server', 'title' => 'Server error', 'message' => 'The server could not finish this request.'],
            503 => ['kind' => 'server', 'title' => 'Unavailable', 'message' => 'Cannot connect to the server.'],
            504 => ['kind' => 'server', 'title' => 'Timed out', 'message' => 'The connection timed out.'],
        ];
    }

    /** @return array{status: int, error_kind: string, title: string, message: string, reference: ?string} */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'error_kind' => $this->kind,
            'title' => $this->title,
            'message' => $this->message,
            'reference' => $this->reference,
        ];
    }

    public function toResponse(Request $request): JsonResponse|Response
    {
        if (self::wantsJson($request)) {
            return response()->json($this->toArray(), $this->status);
        }

        return response()->view('errors.diagnosed', ['error' => $this], $this->status);
    }

    public static function wantsJson(Request $request): bool
    {
        return $request->headers->has('X-Livewire')
            || $request->is('livewire/*', 'api/*', '*/api/*')
            || $request->expectsJson()
            || $request->ajax()
            || $request->wantsJson();
    }

    public static function isFileResponse(Request $request): bool
    {
        return $request->is('dcs/view-document', 'dcs/view-document/*', 'dts/view-document', 'dts/view-document/*');
    }

    private static function specificMessage(Throwable $e): ?string
    {
        $haystack = '';
        $current = $e;
        while ($current instanceof Throwable) {
            $haystack .= ' '.$current::class.' '.$current->getMessage();
            $current = $current->getPrevious();
        }
        $haystack = strtolower($haystack);

        if (str_contains($haystack, 'google') && (str_contains($haystack, 'drive') || str_contains($haystack, 'googleapis') || str_contains($haystack, 'oauth') || str_contains($haystack, 'invalid_grant'))) {
            return 'Cannot connect to Google Drive.';
        }
        if (str_contains($haystack, 'sqlstate[08006]')
            || str_contains($haystack, 'could not translate host name')
            || str_contains($haystack, 'connection refused')
            || str_contains($haystack, 'could not connect to server')
            || str_contains($haystack, 'server closed the connection')
            || str_contains($haystack, 'no connection to the server')
            || str_contains($haystack, 'lost connection')
            || str_contains($haystack, 'connection timed out')) {
            return 'Cannot connect to the database.';
        }
        if (str_contains($haystack, 'redis') && str_contains($haystack, 'connect')) {
            return 'Cannot connect to Redis.';
        }
        if (str_contains($haystack, 'swift') || str_contains($haystack, 'smtp') || str_contains($haystack, 'mailer')) {
            return 'Cannot send the email.';
        }
        if (str_contains($haystack, 'curl error 28') || str_contains($haystack, 'operation timed out')) {
            return 'The connection timed out.';
        }
        if (str_contains($haystack, 'posttoolarge') || str_contains($haystack, 'post too large')) {
            return 'The uploaded file is too large.';
        }

        return null;
    }

    private static function isTechnical(string $message): bool
    {
        return $message === ''
            || strlen($message) > 240
            || preg_match('/SQLSTATE|stack trace|vendor\\\\|\.php on line/i', $message) === 1;
    }
}
