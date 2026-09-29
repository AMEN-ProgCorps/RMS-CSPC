<?php

namespace App\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Livewire\Volt\Volt;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $dcsBootstrap = resource_path('views/pages/dcs/logic/bootstrap.blade.php');
        if (file_exists($dcsBootstrap)) {
            require_once $dcsBootstrap;
        }

        // Replace Blade compiler so Docker/WSL utime failures on compiled views
        // do not take down the page (compiled content is already correct).
        $this->app->singleton('blade.compiler', function ($app) {
            return tap(new \App\View\Compilers\SafeBladeCompiler(
                $app['files'],
                $app['config']['view.compiled'],
                $app['config']->get('view.relative_hash', false) ? $app->basePath() : '',
                $app['config']->get('view.cache', true),
                $app['config']->get('view.compiled_extension', 'php'),
                $app['config']->get('view.check_cache_timestamps', true),
            ), function ($blade) {
                $blade->component('dynamic-component', \Illuminate\View\DynamicComponent::class);
            });
        });
    }

    public function boot(): void
    {
        $compiledViews = env('VIEW_COMPILED_PATH');
        if (is_string($compiledViews) && $compiledViews !== '') {
            config(['view.compiled' => $compiledViews]);
        }

        if (!app()->runningInConsole() && request()->hasHeader('Host')) {
            \Illuminate\Support\Facades\URL::forceRootUrl(request()->schemeAndHttpHost());
        }

        Volt::mount([
            resource_path('views'),
        ]);

        \Illuminate\Cookie\Middleware\EncryptCookies::except('sidebarState');

        Blade::anonymousComponentPath(resource_path('views/layouts'), 'layouts');
    }
}
