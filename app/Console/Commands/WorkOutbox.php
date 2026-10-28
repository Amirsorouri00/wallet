<?php

namespace App\Console\Commands;

use App\Services\Wallet\OutboxDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class WorkOutbox extends Command
{
    protected $signature = 'outbox:work
        {--sleep=1000 : Milliseconds to wait after an empty batch}
        {--batch= : Maximum events to claim per batch}
        {--max-jobs=0 : Stop after claiming this many events; zero means unlimited}
        {--stop-when-empty : Exit after the first empty batch}';

    protected $description = 'Continuously publish transactional outbox events';

    private bool $stopRequested = false;

    public function handle(OutboxDispatcher $dispatcher): int
    {
        $sleep = filter_var($this->option('sleep'), FILTER_VALIDATE_INT);
        $batchOption = $this->option('batch');
        $batch = $batchOption === null
            ? (int) config('wallet.outbox.batch_size')
            : filter_var($batchOption, FILTER_VALIDATE_INT);
        $maxJobs = filter_var($this->option('max-jobs'), FILTER_VALIDATE_INT);

        if ($sleep === false || $sleep < 0 || $sleep > 60_000) {
            $this->error('The --sleep option must be an integer between 0 and 60000 milliseconds.');

            return self::INVALID;
        }

        if ($batch === false || $batch < 1 || $batch > 1000) {
            $this->error('The --batch option must be an integer between 1 and 1000.');

            return self::INVALID;
        }

        if ($maxJobs === false || $maxJobs < 0) {
            $this->error('The --max-jobs option must be a nonnegative integer.');

            return self::INVALID;
        }

        $this->registerSignalHandlers();
        $workerId = sprintf('%s:%d:%s', gethostname() ?: 'localhost', getmypid(), Str::lower(Str::random(8)));
        $claimed = 0;
        $failed = 0;

        while (! $this->stopRequested) {
            $limit = $maxJobs > 0 ? min($batch, $maxJobs - $claimed) : $batch;

            if ($limit < 1) {
                break;
            }

            $report = $dispatcher->dispatchBatch($limit, $workerId);
            $claimed += $report->claimed;
            $failed += $report->failed;
            $this->info(sprintf(
                'Outbox worker batch: claimed=%d published=%d failed=%d total_claimed=%d',
                $report->claimed,
                $report->published,
                $report->failed,
                $claimed,
            ));

            if (($maxJobs > 0 && $claimed >= $maxJobs) || ($report->claimed === 0 && $this->option('stop-when-empty'))) {
                break;
            }

            if ($report->claimed === 0 && $sleep > 0) {
                usleep($sleep * 1000);
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function registerSignalHandlers(): void
    {
        if (! function_exists('pcntl_async_signals') || ! function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);
        $stop = function (): void {
            $this->stopRequested = true;
        };
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
    }
}
