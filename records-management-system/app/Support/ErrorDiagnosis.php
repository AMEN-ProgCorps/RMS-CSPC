<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ErrorDiagnosis
{
    public function __construct(
        public string $kind,
        public int $status,
        public string $message,
        public ?string $reference = null,
    ) {}

    public static function from(Throwable $e): self
    {
        $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
        $specific = self::specificMessage($e);
        if ($status >= 400 && $status < 500) {
            return new self('client', $status, $specific ?? self::clientMessage($e, $status));
        }

        $reference = 'SRV-'.strtoupper(Str::random(6));
        Log::error('Server error '.$reference, [
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'url' => request()?->fullUrl(),
            'user_id' => Auth::id(),
        ]);

        return new self(
            'server',
            $status >= 500 ? $status : 500,
            $specific ?? 'The server could not finish this request.',
            $reference,
        );
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

    /** @return array{error_kind: string, message: string, reference: ?string} */
    public function toArray(): array
    {
        return [
            'error_kind' => $this->kind,
            'message' => $this->message,
            'reference' => $this->reference,
        ];
    }

    private static function clientMessage(Throwable $e, int $status): string
    {
        $message = trim($e->getMessage());
        $technical = $message === ''
            || strlen($message) > 240
            || preg_match('/SQLSTATE|stack trace|vendor\\\\|\.php on line/i', $message) === 1;

        if (! $technical) {
            return $message;
        }

        return match ($status) {
            401 => 'Sign in again before continuing.',
            403 => 'You do not have access to do that.',
            404 => 'That record could not be found.',
            419 => 'Your session expired. Refresh the page and try again.',
            422 => 'Some of the information needs to be corrected before this can continue.',
            default => 'This request could not be completed.',
        };
    }
}
