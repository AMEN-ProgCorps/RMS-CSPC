<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * Server-side DCS PDF upload checks (ignores browser Content-Type / filename).
 *
 * DEBUG MAP:
 *   Upload rejected as not PDF        → pdfProblem / pdfHeadProblem (%PDF- magic)
 *   File too large                    → PDF_MAX_KB / caller maxKilobytes
 *   Used from                         → DocumentStorageService::storeDcsScan*
 */
class DcsUploadGuard
{
    public const PDF_MAX_KB = 204800;

    /**
     * Validates an uploaded file is a real PDF within size limits.
     */
    public static function pdfProblem(UploadedFile $file, int $maxKilobytes): ?string
    {
        if (! $file->isValid() || ! is_string($file->getRealPath()) || ! is_file($file->getRealPath())) {
            return 'The upload did not arrive intact. Choose the PDF again.';
        }

        $originalName = strtolower($file->getClientOriginalName() ?: '');
        $dangerousExtensions = [
            'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'phps',
            'exe', 'bat', 'cmd', 'sh', 'bash', 'bin', 'pl', 'py', 'cgi',
            'jar', 'vbs', 'scr', 'msi', 'com', 'gadget', 'ps1', 'jsp', 'asp', 'aspx'
        ];
        $parts = explode('.', $originalName);
        if (count($parts) > 1) {
            foreach ($parts as $part) {
                if (in_array(trim($part), $dangerousExtensions, true)) {
                    return 'Uploaded file contains prohibited executable extensions or double extension.';
                }
            }
        }

        $size = filesize($file->getRealPath());
        if ($size === false || $size < 5) {
            return 'The file is empty. Upload a PDF.';
        }
        if ($size > $maxKilobytes * 1024) {
            return 'The PDF is too large.';
        }

        $contents = file_get_contents($file->getRealPath());
        if (is_string($contents) && (stripos($contents, '<?php') !== false || stripos($contents, '<?=') !== false)) {
            return 'Uploaded file contains prohibited server-side script tags.';
        }

        $head = substr((string)$contents, 0, 5);

        return self::pdfHeadProblem($head);
    }

    /**
     * Helper used for pdf contents problem.
     */
    public static function pdfContentsProblem(string $contents, int $maxKilobytes): ?string
    {
        $size = strlen($contents);
        if ($size < 5) {
            return 'The file is empty. Upload a PDF.';
        }
        if ($size > $maxKilobytes * 1024) {
            return 'The PDF is too large.';
        }

        if (stripos($contents, '<?php') !== false || stripos($contents, '<?=') !== false) {
            return 'Uploaded file contains prohibited server-side script tags.';
        }

        return self::pdfHeadProblem(substr($contents, 0, 5));
    }

    /**
     * Helper used for image kind.
     */
    public static function imageKind(UploadedFile $file, int $maxKilobytes): ?string
    {
        if (! $file->isValid() || ! is_string($file->getRealPath()) || ! is_file($file->getRealPath())) {
            return null;
        }

        $size = filesize($file->getRealPath());
        if ($size === false || $size < 8 || $size > $maxKilobytes * 1024) {
            return null;
        }

        $head = file_get_contents($file->getRealPath(), false, null, 0, 8);
        if (! is_string($head)) {
            return null;
        }
        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return 'jpeg';
        }
        if (str_starts_with($head, "\x89PNG\r\n\x1a\n")) {
            return 'png';
        }

        return null;
    }

    /**
     * Helper used for pdf head problem.
     */
    private static function pdfHeadProblem(string $head): ?string
    {
        return str_starts_with($head, '%PDF-') ? null : 'Only a PDF file can be uploaded.';
    }
}
