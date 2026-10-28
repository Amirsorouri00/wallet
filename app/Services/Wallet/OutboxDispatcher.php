<?php

namespace App\Services\Wallet;

use App\Contracts\EventPublisher;
use App\Data\Wallet\DispatchReport;
use App\Models\OutboxMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final readonly class OutboxDispatcher
{
    public function __construct(private EventPublisher $publisher) {}

    public function dispatchBatch(int $limit, string $workerId): DispatchReport
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('Outbox batch limit must be between 1 and 1000.');
        }

        if (trim($workerId) === '' || strlen($workerId) > 128) {
            throw new InvalidArgumentException('Outbox worker ID must contain 1 to 128 characters.');
        }

        $messages = $this->claim($limit, $workerId);
        $published = 0;
        $failed = 0;

        foreach ($messages as $message) {
            try {
                $this->publisher->publish($message);

                $marked = DB::table('outbox_messages')
                    ->where('id', $message->id)
                    ->where('lease_owner', $workerId)
                    ->whereNull('published_at')
                    ->whereNull('dead_lettered_at')
                    ->update([
                        'published_at' => now(),
                        'lease_owner' => null,
                        'leased_until' => null,
                        'last_error' => null,
                    ]);

                if ($marked === 1) {
                    $published++;
                } else {
                    $failed++;
                }
            } catch (Throwable $exception) {
                $failed++;
                $this->releaseAfterFailure($message, $workerId, $exception);
            }
        }

        return new DispatchReport(count($messages), $published, $failed);
    }

    /** @return list<OutboxMessage> */
    private function claim(int $limit, string $workerId): array
    {
        $leaseSeconds = max(1, (int) config('wallet.outbox.lease_seconds', 30));

        $rows = DB::transaction(fn (): array => DB::select(<<<'SQL'
            UPDATE outbox_messages AS message
            SET lease_owner = ?,
                leased_until = CURRENT_TIMESTAMP + make_interval(secs => ?)
            FROM (
                SELECT id
                FROM outbox_messages
                WHERE published_at IS NULL
                  AND dead_lettered_at IS NULL
                  AND available_at <= CURRENT_TIMESTAMP
                  AND (leased_until IS NULL OR leased_until <= CURRENT_TIMESTAMP)
                ORDER BY available_at, occurred_at, id
                FOR UPDATE SKIP LOCKED
                LIMIT ?
            ) AS claim
            WHERE message.id = claim.id
            RETURNING message.*
            SQL, [$workerId, $leaseSeconds, $limit]), attempts: 1);

        return array_map(
            static fn (object $row): OutboxMessage => (new OutboxMessage)->newFromBuilder((array) $row),
            $rows,
        );
    }

    private function releaseAfterFailure(OutboxMessage $message, string $workerId, Throwable $exception): void
    {
        $maxAttempts = max(1, (int) config('wallet.outbox.max_attempts', 10));
        $nextAttempts = $message->attempts + 1;
        $baseSeconds = max(1, (int) config('wallet.outbox.base_backoff_seconds', 2));
        $maxSeconds = max($baseSeconds, (int) config('wallet.outbox.max_backoff_seconds', 300));
        $exponent = min($message->attempts, 20);
        $backoffSeconds = min($maxSeconds, $baseSeconds * (2 ** $exponent));

        DB::table('outbox_messages')
            ->where('id', $message->id)
            ->where('lease_owner', $workerId)
            ->whereNull('published_at')
            ->whereNull('dead_lettered_at')
            ->update([
                'attempts' => DB::raw('attempts + 1'),
                'available_at' => now()->addSeconds($backoffSeconds),
                'dead_lettered_at' => $nextAttempts >= $maxAttempts ? now() : null,
                'lease_owner' => null,
                'leased_until' => null,
                'last_error' => Str::limit($exception->getMessage(), 2000, ''),
            ]);
    }
}
