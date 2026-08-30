<?php

namespace App\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Livewire\Volt\Volt;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
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
