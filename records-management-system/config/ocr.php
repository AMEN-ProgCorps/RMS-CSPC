<?php

return [
    /*
    |--------------------------------------------------------------------------
    | OCR engine
    |--------------------------------------------------------------------------
    | paddle = PaddleOCR (recommended). Requires Python + scripts/ocr deps.
    */
    'engine' => env('OCR_ENGINE', 'paddle'),

    'python' => env('OCR_PYTHON', 'python3'),

    'paddle_script' => env(
        'OCR_PADDLE_SCRIPT',
        base_path('scripts/ocr/paddle_ocr.py')
    ),

    /** Shared model cache so PHP-FPM user does not re-download into /root. */
    'paddle_home' => env('PADDLEOCR_HOME', '/opt/paddleocr'),

    /** Seconds allowed for one page OCR (cold start can be slow). */
    'timeout' => (int) env('OCR_TIMEOUT', 120),
];
