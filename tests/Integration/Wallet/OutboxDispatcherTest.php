<?php

namespace Tests\Integration\Wallet;

use App\Contracts\EventPublisher;
use App\Models\OutboxMessage;
use App\Services\Wallet\OutboxDispatcher;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\PostgresTestDatabase;
use Tests\TestCase;

#[Group('integration')]
class OutboxDispatcherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        PostgresTestDatabase::assertPostgres();
        config()->set('wallet.outbox.lease_seconds', 30);
        config()->set('wallet.outbox.base_backoff_seconds', 2);
        config()->set('wallet.outbox.max_backoff_seconds', 10);
        config()->set('wallet.outbox.max_attempts', 100);
    }

    public function test_dispatchers_claim_disjoint_batches_and_publish_after_claim_commit(): void
    {
        $ids = $this->seedMessages(4);
        $baselineTransactionLevel = DB::connection()->transactionLevel();
        $publisher = new class implements EventPublisher
        {
            /** @var list<string> */
            public array $publishedIds = [];

            /** @var list<int> */
            public array $transactionLevels = [];

            public ?Closure $onFirst = null;

            public function publish(OutboxMessage $message): void
            {
                $this->publishedIds[] = $message->id;
                $this->transactionLevels[] = DB::connection()->transactionLevel();

                if ($this->onFirst !== null) {
                    $callback = $this->onFirst;
                    $this->onFirst = null;
                    $callback();
                }
            }
        };
        $this->app->instance(EventPublisher::class, $publisher);

        $secondReport = null;
        $publisher->onFirst = function () use (&$secondReport): void {
            $secondReport = app(OutboxDispatcher::class)->dispatchBatch(2, 'worker-b');
        };

        $firstReport = app(OutboxDispatcher::class)->dispatchBatch(2, 'worker-a');

        self::assertSame(2, $firstReport->claimed);
        self::assertSame(2, $firstReport->published);
        self::assertNotNull($secondReport);
        self::assertSame(2, $secondReport->claimed);
        self::assertSame(2, $secondReport->published);
        self::assertEqualsCanonicalizing($ids, $publisher->publishedIds);
        self::assertSame(array_fill(0, 4, $baselineTransactionLevel), $publisher->transactionLevels);
        self::assertSame(4, DB::table('outbox_messages')->whereNotNull('published_at')->count());
    }

    public function test_failure_is_retried_with_same_event_uuid_and_bounded_backoff(): void
    {
        [$id] = $this->seedMessages(1);
        DB::table('outbox_messages')->where('id', $id)->update(['attempts' => 30]);

        $publisher = new class implements EventPublisher
        {
            /** @var list<string> */
            public array $attemptedIds = [];

            public bool $fail = true;

            public function publish(OutboxMessage $message): void
            {
                $this->attemptedIds[] = $message->id;

                if ($this->fail) {
                    throw new RuntimeException('temporary broker failure');
                }
            }
        };
        $this->app->instance(EventPublisher::class, $publisher);

        $failed = app(OutboxDispatcher::class)->dispatchBatch(1, 'worker-a');
        $afterFailure = DB::table('outbox_messages')->where('id', $id)->first();

        self::assertSame(1, $failed->failed);
        self::assertSame(31, $afterFailure->attempts);
        self::assertNull($afterFailure->lease_owner);
        self::assertNull($afterFailure->leased_until);
        self::assertStringContainsString('temporary broker failure', $afterFailure->last_error);
        self::assertGreaterThanOrEqual(now()->addSeconds(8), $afterFailure->available_at);
        self::assertLessThanOrEqual(now()->addSeconds(12), $afterFailure->available_at);

        DB::table('outbox_messages')->where('id', $id)->update(['available_at' => now()->subSecond()]);
        $publisher->fail = false;
        $retried = app(OutboxDispatcher::class)->dispatchBatch(1, 'worker-b');

        self::assertSame(1, $retried->published);
        self::assertSame([$id, $id], $publisher->attemptedIds);
        self::assertNotNull(DB::table('outbox_messages')->where('id', $id)->value('published_at'));
    }

    public function test_expired_lease_is_reclaimed_and_only_current_owner_can_mark_success(): void
    {
        [$expiredId, $stolenId] = $this->seedMessages(2);
        DB::table('outbox_messages')->where('id', $expiredId)->update([
            'lease_owner' => 'dead-worker',
            'leased_until' => now()->subMinute(),
        ]);

        $publisher = new class($stolenId) implements EventPublisher
        {
            public function __construct(private readonly string $stolenId) {}

            public function publish(OutboxMessage $message): void
            {
                if ($message->id === $this->stolenId) {
                    DB::table('outbox_messages')->where('id', $message->id)->update([
                        'lease_owner' => 'new-owner',
                        'leased_until' => now()->addMinute(),
                    ]);
                }
            }
        };
        $this->app->instance(EventPublisher::class, $publisher);

        $report = app(OutboxDispatcher::class)->dispatchBatch(2, 'worker-a');

        self::assertSame(2, $report->claimed);
        self::assertSame(1, $report->published);
        self::assertSame(1, $report->failed);
        self::assertNotNull(DB::table('outbox_messages')->where('id', $expiredId)->value('published_at'));
        self::assertNull(DB::table('outbox_messages')->where('id', $stolenId)->value('published_at'));
        self::assertSame('new-owner', DB::table('outbox_messages')->where('id', $stolenId)->value('lease_owner'));
    }

    public function test_poison_event_becomes_inspectable_and_is_never_claimed_after_attempt_ceiling(): void
    {
        config()->set('wallet.outbox.max_attempts', 3);
        [$id] = $this->seedMessages(1);
        DB::table('outbox_messages')->where('id', $id)->update(['attempts' => 2]);

        $this->app->instance(EventPublisher::class, new class implements EventPublisher
        {
            public function publish(OutboxMessage $message): void
            {
                throw new RuntimeException('permanent poison payload');
            }
        });

        $failed = app(OutboxDispatcher::class)->dispatchBatch(1, 'worker-a');
        $deadLetter = DB::table('outbox_messages')->where('id', $id)->first();
        $second = app(OutboxDispatcher::class)->dispatchBatch(1, 'worker-b');

        self::assertSame(1, $failed->failed);
        self::assertSame(3, $deadLetter->attempts);
        self::assertNotNull($deadLetter->dead_lettered_at);
        self::assertNull($deadLetter->published_at);
        self::assertNull($deadLetter->lease_owner);
        self::assertStringContainsString('permanent poison payload', $deadLetter->last_error);
        self::assertSame(0, $second->claimed);
    }

    /** @return list<string> */
    private function seedMessages(int $count): array
    {
        $ids = [];

        for ($index = 0; $index < $count; $index++) {
            $id = (string) Str::uuid7();
            $ids[] = $id;
            DB::table('outbox_messages')->insert([
                'id' => $id,
                'event_type' => 'TransferCompleted',
                'aggregate_type' => 'transfer',
                'aggregate_id' => (string) Str::uuid7(),
                'payload' => json_encode(['sequence' => $index], JSON_THROW_ON_ERROR),
                'occurred_at' => now()->addMicroseconds($index),
                'available_at' => now()->subSecond(),
                'attempts' => 0,
            ]);
        }

        return $ids;
    }
}
