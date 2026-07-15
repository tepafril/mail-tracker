<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Auth\HttpJwksProvider;
use App\Services\Auth\JwksProvider;
use App\Services\Auth\TokenVerifier;
use App\Services\Graph\GraphClientFactory;
use App\Services\Smoh\SmohClientFactory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SmohClientFactory::class);
        $this->app->singleton(GraphClientFactory::class);
        $this->app->singleton(JwksProvider::class, HttpJwksProvider::class);
        $this->app->singleton(TokenVerifier::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
