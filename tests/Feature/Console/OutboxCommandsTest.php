<?php

namespace Tests\Feature\Console;

use App\Contracts\EventPublisher;
use App\Models\OutboxMessage;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OutboxCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_stops_when_empty_and_honors_max_jobs(): void
    {
        $this->artisan('outbox:work', ['--stop-when-empty' => true])
            ->expectsOutputToContain('claimed=0')
            ->assertSuccessful();

        $this->seedMessages(2);
        $publisher = new class implements EventPublisher
        {
            /** @var list<string> */
            public array $ids = [];

            public function publish(OutboxMessage $message): void
            {
                $this->ids[] = $message->id;
            }
        };
        $this->app->instance(EventPublisher::class, $publisher);

        $this->artisan('outbox:work', [
            '--batch' => 100,
            '--max-jobs' => 1,
            '--stop-when-empty' => true,
        ])->expectsOutputToContain('claimed=1')
            ->assertSuccessful();

        self::assertCount(1, $publisher->ids);
        self::assertSame(1, DB::table('outbox_messages')->whereNotNull('published_at')->count());
        self::assertSame(1, DB::table('outbox_messages')->whereNull('published_at')->count());
    }

    public function test_status_reports_operational_counts_and_fails_when_due_lag_exceeds_threshold(): void
    {
        [$due, $leased, $published, $dead] = $this->seedMessages(4);
        DB::table('outbox_messages')->where('id', $due)->update([
            'occurred_at' => now()->subSeconds(90),
            'available_at' => now()->subSeconds(90),
        ]);
        DB::table('outbox_messages')->where('id', $leased)->update([
            'lease_owner' => 'worker-a',
            'leased_until' => now()->addMinute(),
        ]);
        DB::table('outbox_messages')->where('id', $published)->update(['published_at' => now()]);
        DB::table('outbox_messages')->where('id', $dead)->update([
            'attempts' => 10,
            'dead_lettered_at' => now(),
            'last_error' => 'poison event',
        ]);

        self::assertSame(Command::FAILURE, Artisan::call('outbox:status', ['--max-lag' => 30]));
        $output = Artisan::output();
        self::assertStringContainsString('due=1', $output);
        self::assertStringContainsString('leased=1', $output);
        self::assertStringContainsString('published=1', $output);
        self::assertStringContainsString('dead_lettered=1', $output);

        self::assertSame(Command::SUCCESS, Artisan::call('outbox:status', ['--max-lag' => 120]));
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
                'occurred_at' => now(),
                'available_at' => now()->subSecond(),
                'attempts' => 0,
            ]);
        }

        return $ids;
    }
}
