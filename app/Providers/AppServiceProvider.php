<?php

namespace App\Providers;

use App\Support\QueueWorkerHeartbeat;
use Illuminate\Foundation\DevCommands;
use Illuminate\Queue\Events\JobPopping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Fired on every queue poll by both `queue:work` and `queue:listen`
        Event::listen(JobPopping::class, fn () => QueueWorkerHeartbeat::beat());

        // `php artisan dev` runs several downloads at once, like the Settings page asks
        DevCommands::artisan('downloads:work', 'queue');
    }
}
