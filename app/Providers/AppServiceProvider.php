<?php

namespace App\Providers;

use App\OpenApi\WalletApiDocumentTransformer;
use App\OpenApi\WalletApiOperationTransformer;
use Dedoc\Scramble\Scramble;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        RateLimiter::for('wallet-writes', fn (Request $request): Limit => Limit::perMinute(
            (int) config('wallet.rate_limits.writes_per_minute'),
        )->by('write:'.$this->routeIdentity($request, 'wallet')));
        RateLimiter::for('gateway-callbacks', fn (Request $request): Limit => Limit::perMinute(
            (int) config('wallet.rate_limits.gateway_callbacks_per_minute'),
        )->by('gateway:'.$request->ip()));
        RateLimiter::for('admin-commands', fn (Request $request): Limit => Limit::perMinute(
            (int) config('wallet.rate_limits.admin_commands_per_minute'),
        )->by('admin:'.$request->ip()));
        RateLimiter::for('wallet-reads', fn (Request $request): Limit => Limit::perMinute(
            (int) config('wallet.rate_limits.reads_per_minute'),
        )->by('read:'.$request->ip()));

        Scramble::configure()
            ->withOperationTransformers(WalletApiOperationTransformer::class)
            ->withDocumentTransformers(WalletApiDocumentTransformer::class);
    }

    private function routeIdentity(Request $request, string $parameter): string
    {
        $value = $request->route($parameter);

        if (is_object($value) && method_exists($value, 'getRouteKey')) {
            return (string) $value->getRouteKey();
        }

        return is_scalar($value) ? (string) $value : (string) $request->ip();
    }
}
