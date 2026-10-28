<?php

namespace Tests\Feature\Api;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RateLimitContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_route_family_uses_its_named_rate_limit_policy(): void
    {
        $expected = [
            'v1.wallets.deposits.store' => 'throttle:wallet-writes',
            'v1.wallets.transfers.store' => 'throttle:wallet-writes',
            'v1.wallets.withdrawals.store' => 'throttle:wallet-writes',
            'v1.gateway.deposits.confirm' => 'throttle:gateway-callbacks',
            'v1.gateway.deposits.fail' => 'throttle:gateway-callbacks',
            'v1.admin.withdrawals.approve' => 'throttle:admin-commands',
            'v1.admin.withdrawals.settle' => 'throttle:admin-commands',
            'v1.admin.withdrawals.reject' => 'throttle:admin-commands',
            'v1.deposits.show' => 'throttle:wallet-reads',
            'v1.withdrawals.show' => 'throttle:wallet-reads',
            'v1.wallets.transactions.index' => 'throttle:wallet-reads',
        ];

        foreach ($expected as $name => $middleware) {
            $route = Route::getRoutes()->getByName($name);
            self::assertNotNull($route, $name);
            self::assertContains($middleware, $route->gatherMiddleware(), $name);
        }
    }

    public function test_rate_limit_exhaustion_uses_the_shared_retryable_problem_contract(): void
    {
        config()->set('wallet.rate_limits.writes_per_minute', 1);
        RateLimiter::clear('wallet-write:127.0.0.1');
        $this->seed(DatabaseSeeder::class);
        $walletId = DB::table('wallets')->where('owner_id', 'demo-user-alice')->value('id');

        $this->withHeader('Idempotency-Key', 'rate-limit-1')
            ->postJson("/api/v1/wallets/{$walletId}/deposits", ['amount_minor' => 1])
            ->assertCreated();

        $this->withHeader('Idempotency-Key', 'rate-limit-2')
            ->postJson("/api/v1/wallets/{$walletId}/deposits", ['amount_minor' => 1])
            ->assertTooManyRequests()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertHeader('Retry-After')
            ->assertJsonPath('code', 'RATE_LIMIT_EXCEEDED')
            ->assertJsonPath('retryable', true);
    }
}
