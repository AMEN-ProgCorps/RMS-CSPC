<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Runs scripts/ocr/paddle_ocr.py and returns normalized word/line boxes.
 */
class PaddleOcrRunner
{
    /**
     * @return array{
     *   ok: bool,
     *   text: string,
     *   words: list<array{t: string, x: float, y: float, w: float, h: float, conf?: float|null}>,
     *   lines: list<array{t: string, y: float, w: float, h: float}>,
     *   image_w?: int,
     *   image_h?: int,
     *   error?: string
     * }
     */
    public static function recognize(string $imagePath): array
    {
        $python = self::resolvePythonBinary();
        $script = (string) config('ocr.paddle_script', base_path('scripts/ocr/paddle_ocr.py'));
        $timeout = max(30, (int) config('ocr.timeout', 180));

        if (! is_file($imagePath)) {
            return self::emptyFailure('OCR image not found.');
        }
        if (! is_file($script)) {
            return self::emptyFailure('PaddleOCR script missing: '.$script);
        }
        if (str_contains($python, '/') && ! is_file($python)) {
            return self::emptyFailure(
                'OCR Python not found at '.$python
                .' — rebuild the app image (Dockerfile paddle-venv) or set OCR_PYTHON.'
            );
        }

        $paddleHome = self::resolveWritablePaddleHome();
        $env = self::processEnvironment([
            'PADDLEOCR_HOME' => $paddleHome,
            'HOME' => $paddleHome,
            'PYTHONUNBUFFERED' => '1',
            'LANG' => 'C.UTF-8',
            'LC_ALL' => 'C.UTF-8',
        ]);

        $process = new Process([$python, $script, $imagePath], base_path(), $env);
        $process->setTimeout($timeout);
        $process->run();

        $stdout = trim($process->getOutput());
        $stderr = trim($process->getErrorOutput());

        if ($stdout === '') {
            Log::warning('PaddleOCR empty stdout', [
                'python' => $python,
                'script' => $script,
                'paddle_home' => $paddleHome,
                'exit' => $process->getExitCode(),
                'stderr' => mb_substr($stderr, 0, 1500),
            ]);

            $hint = $stderr !== '' ? $stderr : 'PaddleOCR returned no output.';
            if ($process->getExitCode() === 127 || str_contains($stderr, 'No module named')) {
                $hint .= ' (Install paddle deps / use OCR_PYTHON=/opt/paddle-venv/bin/python)';
            }

            return self::emptyFailure($hint);
        }

        $data = json_decode($stdout, true);
        // Paddle may print download/progress lines before JSON; take the last JSON object.
        if (! is_array($data)) {
            $data = self::decodeTrailingJson($stdout);
        }
        if (! is_array($data)) {
            Log::warning('PaddleOCR invalid JSON', ['stdout' => mb_substr($stdout, 0, 500)]);

            return self::emptyFailure('PaddleOCR returned invalid JSON.');
        }

        if (! ($data['ok'] ?? false) && ($data['text'] ?? '') === '') {
            return [
                'ok' => false,
                'text' => '',
                'words' => [],
                'lines' => [],
                'blocks' => [],
                'tables' => [],
                'signatures' => [],
                'footers' => [],
                'figures' => [],
                'image_w' => (int) ($data['image_w'] ?? 0),
                'image_h' => (int) ($data['image_h'] ?? 0),
                'error' => (string) ($data['error'] ?? $stderr ?: 'OCR failed'),
            ];
        }

        return [
            'ok' => (string) ($data['text'] ?? '') !== '',
            'text' => (string) ($data['text'] ?? ''),
            'words' => is_array($data['words'] ?? null) ? $data['words'] : [],
            'lines' => is_array($data['lines'] ?? null) ? $data['lines'] : [],
            'blocks' => is_array($data['blocks'] ?? null) ? $data['blocks'] : [],
            'tables' => is_array($data['tables'] ?? null) ? $data['tables'] : [],
            'signatures' => is_array($data['signatures'] ?? null) ? $data['signatures'] : [],
            'footers' => is_array($data['footers'] ?? null) ? $data['footers'] : [],
            'figures' => is_array($data['figures'] ?? null) ? $data['figures'] : [],
            'engine' => (string) ($data['engine'] ?? 'paddleocr'),
            'image_w' => (int) ($data['image_w'] ?? 0),
            'image_h' => (int) ($data['image_h'] ?? 0),
        ];
    }

    /**
     * @return array{ok: bool, text: string, words: array, lines: array, error: string}
     */
    private static function emptyFailure(string $message): array
    {
        return [
            'ok' => false,
            'text' => '',
            'words' => [],
            'lines' => [],
            'blocks' => [],
            'tables' => [],
            'signatures' => [],
            'footers' => [],
            'figures' => [],
            'error' => $message,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decodeTrailingJson(string $stdout): ?array
    {
        $lines = preg_split('/\r\n|\r|\n/', $stdout) ?: [];
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if ($line === '' || ($line[0] ?? '') !== '{') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $start = strrpos($stdout, '{');
        if ($start === false) {
            return null;
        }
        $decoded = json_decode(substr($stdout, $start), true);

        return is_array($decoded) ? $decoded : null;
    }

    /** Prefer configured venv python on deploy; fall back to common paths. */
    private static function resolvePythonBinary(): string
    {
        $configured = trim((string) config('ocr.python', 'python3'));
        $candidates = array_values(array_unique(array_filter([
            $configured,
            '/opt/paddle-venv/bin/python',
            '/opt/paddle-venv/bin/python3',
            '/usr/bin/python3',
            'python3',
        ])));

        foreach ($candidates as $bin) {
            if (str_contains($bin, '/')) {
                if (is_file($bin) && (is_executable($bin) || @is_executable(realpath($bin) ?: $bin))) {
                    return $bin;
                }
                continue;
            }

            // Bare command name — let Process resolve via PATH.
            return $bin;
        }

        return $configured !== '' ? $configured : 'python3';
    }

    /** Pick a cache dir PHP-FPM can write (models must not live under /root). */
    private static function resolveWritablePaddleHome(): string
    {
        $candidates = array_values(array_unique(array_filter([
            (string) config('ocr.paddle_home', '/opt/paddleocr'),
            '/opt/paddleocr',
            storage_path('app/paddleocr'),
            '/tmp/paddleocr',
        ])));

        foreach ($candidates as $dir) {
            if ($dir === '') {
                continue;
            }
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            if (! is_dir($dir)) {
                continue;
            }
            $probe = rtrim($dir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.write_test_'.getmypid();
            if (@file_put_contents($probe, 'ok') !== false) {
                @unlink($probe);

                return $dir;
            }
        }

        return '/tmp/paddleocr';
    }

    /**
     * Build a complete env for the OCR child process.
     * PHP-FPM often has a tiny PATH; Symfony setEnv alone can drop needed vars.
     *
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private static function processEnvironment(array $overrides): array
    {
        $env = [];
        foreach (array_merge($_SERVER, $_ENV) as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                continue;
            }
            if ($key === '' || str_starts_with($key, 'HTTP_')) {
                continue;
            }
            $env[$key] = $value;
        }

        $path = $env['PATH'] ?? (getenv('PATH') ?: '');
        if ($path === '' || ! str_contains($path, '/usr/bin')) {
            $path = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
        }
        $env['PATH'] = $path;

        foreach ($overrides as $key => $value) {
            $env[$key] = $value;
        }

        return $env;
    }
}
