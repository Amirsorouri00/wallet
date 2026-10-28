<?php

namespace App\Console\Commands;

use App\Services\Wallet\OutboxDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class PublishOutbox extends Command
{
    protected $signature = 'outbox:publish {--limit= : Maximum events to claim} {--worker= : Stable worker identifier}';

    protected $description = 'Publish one leased batch of transactional outbox events';

    public function handle(OutboxDispatcher $dispatcher): int
    {
        $option = $this->option('limit');
        $limit = $option === null
            ? (int) config('wallet.outbox.batch_size')
            : filter_var($option, FILTER_VALIDATE_INT);

        if ($limit === false || $limit < 1 || $limit > 1000) {
            $this->error('The --limit option must be an integer between 1 and 1000.');

            return self::INVALID;
        }

        $worker = $this->option('worker');
        $workerId = is_string($worker) && trim($worker) !== ''
            ? $worker
            : sprintf('%s:%d:%s', gethostname() ?: 'localhost', getmypid(), Str::lower(Str::random(8)));

        $report = $dispatcher->dispatchBatch($limit, $workerId);
        $this->info(sprintf(
            'Outbox batch complete: claimed=%d published=%d failed=%d',
            $report->claimed,
            $report->published,
            $report->failed,
        ));

        return $report->failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
