<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\School;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        /**Set Universal Variable */
        try {
            $school = School::find(1);
            view()->share(['school' => $school]);
        } catch (\Exception $e) {
            // Handle case where school table doesn't exist yet (during migrations)
        }
    }
}
