<?php

namespace App\View\Compilers;

use ErrorException;
use Illuminate\View\Compilers\BladeCompiler;

/**
 * Docker/WSL can refuse Blade's mtime bump (touch/utime) even when the compiled
 * file content is already correct — Laravel then fatals the whole request.
 *
 * Also serializes compiles per view path so PHP-FPM workers cannot race-write
 * the same compiled file under VIEW_COMPILED_PATH=/tmp (intermittent parse errors).
 */
class SafeBladeCompiler extends BladeCompiler
{
    public function compile($path = null)
    {
        if ($path) {
            $this->setPath($path);
        }

        if (is_null($this->cachePath)) {
            return;
        }

        $compiledPath = $this->getCompiledPath($this->getPath());
        $this->ensureCompiledDirectoryExists($compiledPath);

        $lockPath = $compiledPath.'.lock';
        $lock = @fopen($lockPath, 'c+');
        if ($lock === false) {
            $this->compileUnlocked($path);

            return;
        }

        try {
            if (! flock($lock, LOCK_EX)) {
                $this->compileUnlocked($path);

                return;
            }

            // Another worker may have finished a fresh compile while we waited.
            if (! $this->isExpired($this->getPath())) {
                return;
            }

            $this->compileUnlocked($path);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Runs the normal Blade compile, ignoring WSL touch/utime failures.
     */
    private function compileUnlocked(?string $path): void
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
