<?php

namespace App\Providers;

use DateInterval;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Passport::tokensExpireIn(new DateInterval('PT120M'));
        Passport::personalAccessTokensExpireIn(new DateInterval('PT120M'));
    }
}
