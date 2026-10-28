<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class OutboxStatus extends Command
{
    protected $signature = 'outbox:status {--max-lag= : Fail when oldest due event age exceeds this many seconds}';

    protected $description = 'Report transactional outbox health and lag';

    public function handle(): int
    {
        $maxLagOption = $this->option('max-lag');
        $maxLag = $maxLagOption === null ? null : filter_var($maxLagOption, FILTER_VALIDATE_INT);

        if ($maxLag === false || ($maxLag !== null && $maxLag < 0)) {
            $this->error('The --max-lag option must be a nonnegative integer.');

            return self::INVALID;
        }

        $status = DB::selectOne(<<<'SQL'
            SELECT
                COUNT(*) FILTER (
                    WHERE published_at IS NULL
                      AND dead_lettered_at IS NULL
                      AND available_at <= CURRENT_TIMESTAMP
                      AND (leased_until IS NULL OR leased_until <= CURRENT_TIMESTAMP)
                )::integer AS due,
                COUNT(*) FILTER (
                    WHERE published_at IS NULL
                      AND dead_lettered_at IS NULL
                      AND leased_until > CURRENT_TIMESTAMP
                )::integer AS leased,
                COUNT(*) FILTER (WHERE published_at IS NOT NULL)::integer AS published,
                COUNT(*) FILTER (WHERE dead_lettered_at IS NOT NULL)::integer AS dead_lettered,
                COUNT(*) FILTER (
                    WHERE attempts > 0
                      AND published_at IS NULL
                      AND dead_lettered_at IS NULL
                )::integer AS retrying,
                COALESCE(MAX(attempts), 0)::integer AS maximum_attempts,
                COALESCE(EXTRACT(EPOCH FROM (
                    CURRENT_TIMESTAMP - MIN(occurred_at) FILTER (
                        WHERE published_at IS NULL
                          AND dead_lettered_at IS NULL
                          AND available_at <= CURRENT_TIMESTAMP
                          AND (leased_until IS NULL OR leased_until <= CURRENT_TIMESTAMP)
                    )
                )), 0)::integer AS oldest_due_lag_seconds
            FROM outbox_messages
            SQL);

        $this->info(sprintf(
            'Outbox status: due=%d leased=%d published=%d retrying=%d dead_lettered=%d oldest_due_lag_seconds=%d maximum_attempts=%d',
            $status->due,
            $status->leased,
            $status->published,
            $status->retrying,
            $status->dead_lettered,
            $status->oldest_due_lag_seconds,
            $status->maximum_attempts,
        ));

        if ($maxLag !== null && $status->oldest_due_lag_seconds > $maxLag) {
            $this->error(sprintf(
                'Oldest due outbox event exceeds the %d second lag threshold.',
                $maxLag,
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
