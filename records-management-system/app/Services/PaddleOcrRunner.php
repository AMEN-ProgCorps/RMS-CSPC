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
     *   lines: list<array{t: string, x: float, y: float, w: float, h: float}>,
     *   image_w?: int,
     *   image_h?: int,
     *   error?: string
     * }
     */
    public static function recognize(string $imagePath): array
    {
        $python = (string) config('ocr.python', 'python3');
        $script = (string) config('ocr.paddle_script', base_path('scripts/ocr/paddle_ocr.py'));
        $timeout = max(30, (int) config('ocr.timeout', 120));

        if (! is_file($imagePath)) {
            return self::emptyFailure('OCR image not found.');
        }
        if (! is_file($script)) {
            return self::emptyFailure('PaddleOCR script missing: '.$script);
        }

        $process = new Process([$python, $script, $imagePath]);
        $process->setTimeout($timeout);
        $paddleHome = (string) config('ocr.paddle_home', '/opt/paddleocr');
        if ($paddleHome !== '') {
            $process->setEnv([
                'PADDLEOCR_HOME' => $paddleHome,
                'HOME' => $paddleHome,
            ]);
        }
        $process->run();

        $stdout = trim($process->getOutput());
        $stderr = trim($process->getErrorOutput());

        if ($stdout === '') {
            Log::warning('PaddleOCR empty stdout', [
                'exit' => $process->getExitCode(),
                'stderr' => $stderr,
            ]);

            return self::emptyFailure($stderr !== '' ? $stderr : 'PaddleOCR returned no output.');
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
}
