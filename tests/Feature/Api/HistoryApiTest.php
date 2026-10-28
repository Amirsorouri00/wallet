<?php

namespace Tests\Feature\Api;

use App\Actions\Wallet\ConfirmDeposit;
use App\Actions\Wallet\CreateDeposit;
use App\Actions\Wallet\FailDeposit;
use App\Actions\Wallet\TransferFunds;
use App\Data\Wallet\GatewayCallback;
use App\Services\Wallet\WalletProvisioner;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HistoryApiTest extends TestCase
{
    use RefreshDatabase;

    private string $alice;

    private string $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->alice = DB::table('wallets')->where('owner_id', 'demo-user-alice')->value('id');
        $this->bob = DB::table('wallets')->where('owner_id', 'demo-user-bob')->value('id');
        $this->confirmDeposit($this->alice, 100, 'history-deposit-1');
        app(TransferFunds::class)->handle($this->alice, $this->bob, 30, 'history-transfer');
        $this->confirmDeposit($this->alice, 20, 'history-deposit-2');

        app(CreateDeposit::class)->handle($this->alice, 999, 'pending-not-history');
        $failed = app(CreateDeposit::class)->handle($this->alice, 888, 'failed-not-history')->body()['data'];
        app(FailDeposit::class)->handle(
            $failed['deposit_id'],
            new GatewayCallback($failed['gateway_reference'], 888),
        );
    }

    public function test_history_is_wallet_scoped_and_uses_descending_state_versions(): void
    {
        $response = $this->getJson("/api/v1/wallets/{$this->alice}/transactions")
            ->assertOk()
            ->assertJsonPath('meta.next_cursor', null)
            ->assertJsonCount(3, 'data');

        self::assertSame([3, 2, 1], array_column($response->json('data'), 'version'));
        self::assertSame([20, -30, 100], array_column($response->json('data'), 'delta_minor'));
        self::assertSame([90, 70, 100], array_column($response->json('data'), 'balance_after_minor'));
        self::assertSame(['deposit', 'transfer', 'deposit'], array_column($response->json('data'), 'type'));
        self::assertSame(['credit', 'debit', 'credit'], array_column($response->json('data'), 'direction'));

        $this->getJson("/api/v1/wallets/{$this->bob}/transactions")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.delta_minor', 30)
            ->assertJsonPath('data.0.balance_after_minor', 30);
    }

    public function test_history_filters_use_inclusive_from_and_exclusive_to(): void
    {
        $this->getJson("/api/v1/wallets/{$this->alice}/transactions?type=transfer")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'transfer');
        $this->getJson("/api/v1/wallets/{$this->alice}/transactions?direction=credit")
            ->assertOk()
            ->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/wallets/{$this->alice}/transactions?direction=debit")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $times = DB::table('wallet_balance_states')
            ->where('wallet_id', $this->alice)
            ->whereIn('version', [2, 3])
            ->pluck('created_at', 'version');
        $from = rawurlencode((new \DateTimeImmutable($times[2]))->format('Y-m-d\TH:i:s.uP'));
        $to = rawurlencode((new \DateTimeImmutable($times[3]))->format('Y-m-d\TH:i:s.uP'));

        $this->getJson("/api/v1/wallets/{$this->alice}/transactions?from={$from}")
            ->assertOk()
            ->assertJsonCount(3, 'data');
        $this->getJson("/api/v1/wallets/{$this->alice}/transactions?to={$to}")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_cursor_is_opaque_signed_and_bound_to_wallet_and_filters(): void
    {
        $first = $this->getJson("/api/v1/wallets/{$this->alice}/transactions?per_page=2")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2);
        $cursor = $first->json('meta.next_cursor');
        self::assertIsString($cursor);
        self::assertStringNotContainsString($this->alice, $cursor);

        $second = $this->getJson("/api/v1/wallets/{$this->alice}/transactions?per_page=2&cursor=".rawurlencode($cursor))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.next_cursor', null);
        self::assertSame([1], array_column($second->json('data'), 'version'));

        $this->getJson("/api/v1/wallets/{$this->bob}/transactions?per_page=2&cursor=".rawurlencode($cursor))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->getJson("/api/v1/wallets/{$this->alice}/transactions?per_page=2&type=deposit&cursor=".rawurlencode($cursor))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->getJson("/api/v1/wallets/{$this->alice}/transactions?per_page=1000")
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_cursor_rejects_tampering(): void
    {
        $cursor = $this->getJson("/api/v1/wallets/{$this->alice}/transactions?per_page=2")
            ->assertOk()
            ->json('meta.next_cursor');
        $tampered = substr_replace($cursor, $cursor[0] === 'A' ? 'B' : 'A', 0, 1);

        $this->getJson("/api/v1/wallets/{$this->alice}/transactions?per_page=2&cursor=".rawurlencode($tampered))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    public function test_append_between_pages_does_not_duplicate_or_skip_older_items(): void
    {
        $first = $this->getJson("/api/v1/wallets/{$this->alice}/transactions?per_page=2")
            ->assertOk();
        $cursor = $first->json('meta.next_cursor');

        $this->confirmDeposit($this->alice, 10, 'history-between-pages');

        $second = $this->getJson("/api/v1/wallets/{$this->alice}/transactions?per_page=2&cursor=".rawurlencode($cursor))
            ->assertOk()
            ->assertJsonPath('meta.next_cursor', null);

        self::assertSame([3, 2], array_column($first->json('data'), 'version'));
        self::assertSame([1], array_column($second->json('data'), 'version'));
        self::assertSame([3, 2, 1], array_column([...$first->json('data'), ...$second->json('data')], 'version'));
    }

    public function test_empty_history_returns_an_empty_page(): void
    {
        $emptyWallet = app(WalletProvisioner::class)->create('user', 'empty-history-user')->id;

        $this->getJson("/api/v1/wallets/{$emptyWallet}/transactions?per_page=1")
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.next_cursor', null)
            ->assertJsonPath('meta.per_page', 1);
    }

    public function test_history_query_count_does_not_grow_with_items(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson("/api/v1/wallets/{$this->alice}/transactions?per_page=1")->assertOk();
        $singleItemQueries = count(DB::getQueryLog());

        DB::flushQueryLog();
        $this->getJson("/api/v1/wallets/{$this->alice}/transactions?per_page=3")->assertOk();
        $threeItemQueries = count(DB::getQueryLog());

        self::assertSame($singleItemQueries, $threeItemQueries);
        self::assertLessThanOrEqual(3, $threeItemQueries);
    }

    private function confirmDeposit(string $walletId, int $amountMinor, string $key): void
    {
        $pending = app(CreateDeposit::class)->handle($walletId, $amountMinor, $key)->body()['data'];
        app(ConfirmDeposit::class)->handle(
            $pending['deposit_id'],
            new GatewayCallback($pending['gateway_reference'], $amountMinor),
        );
    }
}
