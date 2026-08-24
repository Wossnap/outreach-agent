<?php

namespace App\Providers;

use App\Services\Drafting\AnthropicDrafter;
use App\Services\Drafting\Drafter;
use App\Services\Drafting\MockDrafter;
use App\Services\Health\DnsResolver;
use App\Services\Health\PhpDnsResolver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(DnsResolver::class, PhpDnsResolver::class);

        $this->app->bind(Drafter::class, fn () => config('services.anthropic.drafter') === 'mock'
            ? new MockDrafter
            : new AnthropicDrafter);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
