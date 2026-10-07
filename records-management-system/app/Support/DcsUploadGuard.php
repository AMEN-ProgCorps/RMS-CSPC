<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * Server-side upload checks. The browser Content-Type and filename are ignored.
 */
class DcsUploadGuard
{
    public const PDF_MAX_KB = 204800;

    public static function pdfProblem(UploadedFile $file, int $maxKilobytes): ?string
    {
        if (! $file->isValid() || ! is_string($file->getRealPath()) || ! is_file($file->getRealPath())) {
            return 'The upload did not arrive intact. Choose the PDF again.';
        }

        $size = filesize($file->getRealPath());
        if ($size === false || $size < 5) {
            return 'The file is empty. Upload a PDF.';
        }
        if ($size > $maxKilobytes * 1024) {
            return 'The PDF is too large.';
        }

        $head = file_get_contents($file->getRealPath(), false, null, 0, 5);

        return self::pdfHeadProblem(is_string($head) ? $head : '');
    }

    public static function pdfContentsProblem(string $contents, int $maxKilobytes): ?string
    {
        $size = strlen($contents);
        if ($size < 5) {
            return 'The file is empty. Upload a PDF.';
        }
        if ($size > $maxKilobytes * 1024) {
            return 'The PDF is too large.';
        }

        return self::pdfHeadProblem(substr($contents, 0, 5));
    }

    /**
     * JPEG or PNG only, decided from the file bytes.
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

    private static function pdfHeadProblem(string $head): ?string
    {
        return str_starts_with($head, '%PDF-') ? null : 'Only a PDF file can be uploaded.';
    }
}
