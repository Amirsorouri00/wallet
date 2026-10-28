<?php

namespace App\Providers;

use App\Contracts\EventPublisher;
use App\Infrastructure\LoggingEventPublisher;
use Illuminate\Support\ServiceProvider;

class WalletServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EventPublisher::class, LoggingEventPublisher::class);
    }
}
