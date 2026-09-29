<?php

namespace App\View\Compilers;

use ErrorException;
use Illuminate\View\Compilers\BladeCompiler;

/**
 * Docker/WSL can refuse Blade's mtime bump (touch/utime) even when the compiled
 * file content is already correct — Laravel then fatals the whole request.
 */
class SafeBladeCompiler extends BladeCompiler
{
    public function compile($path = null)
    {
        try {
            parent::compile($path);
        } catch (ErrorException $e) {
            $message = $e->getMessage();
            if (
                ! str_contains($message, 'touch():')
                && ! str_contains($message, 'Utime failed')
            ) {
                throw $e;
            }
        }
    }
}
