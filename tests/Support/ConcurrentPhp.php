<?php

namespace Tests\Support;

use RuntimeException;

final class ConcurrentPhp
{
    /**
     * @param  list<array{source: string, destination: string, amount: int, key: string}>  $transfers
     * @return list<array<string, mixed>>
     */
    public static function runTransfers(array $transfers): array
    {
        $workers = array_map(fn (array $transfer): array => [
            'script' => base_path('tests/Support/TransferWorker.php'),
            'arguments' => [
                $transfer['source'],
                $transfer['destination'],
                (string) $transfer['amount'],
                $transfer['key'],
            ],
        ], $transfers);

        return self::runWorkers($workers, 'Transfer');
    }

    /**
     * @param  list<array{action: string, deposit: string, reference: string, amount: int}>  $callbacks
     * @return list<array<string, mixed>>
     */
    public static function runDepositCallbacks(array $callbacks): array
    {
        $workers = array_map(fn (array $callback): array => [
            'script' => base_path('tests/Support/DepositCallbackWorker.php'),
            'arguments' => [
                $callback['action'],
                $callback['deposit'],
                $callback['reference'],
                (string) $callback['amount'],
            ],
        ], $callbacks);

        return self::runWorkers($workers, 'Deposit callback');
    }

    /**
     * @param  array{source: string, destination: string, amount: int, key: string}  $transfer
     * @return list<array<string, mixed>>
     */
    public static function runTransferAgainstWithdrawalApproval(
        array $transfer,
        string $withdrawalId,
        string $approvalKey,
    ): array {
        return self::runWorkers([
            [
                'script' => base_path('tests/Support/TransferWorker.php'),
                'arguments' => [
                    $transfer['source'],
                    $transfer['destination'],
                    (string) $transfer['amount'],
                    $transfer['key'],
                ],
            ],
            [
                'script' => base_path('tests/Support/WithdrawalApprovalWorker.php'),
                'arguments' => [$withdrawalId, $approvalKey],
            ],
        ], 'Transfer/withdrawal approval');
    }

    /**
     * @param  list<array{action: string, withdrawal: string, key: string, reason?: string}>  $commands
     * @return list<array<string, mixed>>
     */
    public static function runWithdrawalCommands(array $commands): array
    {
        $workers = array_map(fn (array $command): array => [
            'script' => base_path('tests/Support/WithdrawalCommandWorker.php'),
            'arguments' => [
                $command['action'],
                $command['withdrawal'],
                $command['key'],
                $command['reason'] ?? '-',
            ],
        ], $commands);

        return self::runWorkers($workers, 'Withdrawal command');
    }

    /**
     * @param  list<array{script: string, arguments: list<string>}>  $workers
     * @return list<array<string, mixed>>
     */
    private static function runWorkers(array $workers, string $label): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'taline-concurrency-'.bin2hex(random_bytes(8));

        if (! mkdir($directory, 0700) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the concurrency test directory.');
        }

        $barrier = $directory.DIRECTORY_SEPARATOR.'start';
        $processes = [];

        try {
            foreach ($workers as $index => $worker) {
                $ready = $directory.DIRECTORY_SEPARATOR."ready-{$index}";
                $result = $directory.DIRECTORY_SEPARATOR."result-{$index}.json";
                $command = [
                    PHP_BINARY,
                    $worker['script'],
                    ...$worker['arguments'],
                    $ready,
                    $barrier,
                    $result,
                ];
                $pipes = [];
                $process = proc_open($command, [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ], $pipes, base_path());

                if (! is_resource($process)) {
                    throw new RuntimeException("Unable to start a {$label} worker.");
                }

                fclose($pipes[0]);
                $processes[] = compact('process', 'pipes', 'ready', 'result');
            }

            $deadline = microtime(true) + 15;

            do {
                $allReady = array_all($processes, fn (array $worker): bool => file_exists($worker['ready']));

                if ($allReady) {
                    break;
                }

                usleep(10_000);
            } while (microtime(true) < $deadline);

            if (! $allReady) {
                throw new RuntimeException("{$label} workers did not reach the start barrier.");
            }

            touch($barrier);
            $responses = [];

            foreach ($processes as $worker) {
                $stdout = stream_get_contents($worker['pipes'][1]);
                $stderr = stream_get_contents($worker['pipes'][2]);
                fclose($worker['pipes'][1]);
                fclose($worker['pipes'][2]);
                $exitCode = proc_close($worker['process']);

                if ($exitCode !== 0 || ! file_exists($worker['result'])) {
                    throw new RuntimeException(sprintf(
                        '%s worker failed with exit %d. stdout=%s stderr=%s',
                        $label,
                        $exitCode,
                        $stdout,
                        $stderr,
                    ));
                }

                $responses[] = json_decode(
                    file_get_contents($worker['result']),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );
            }

            return $responses;
        } finally {
            foreach ($processes as $worker) {
                if (is_resource($worker['process'])) {
                    proc_terminate($worker['process']);
                }
            }

            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($directory);
        }
    }
}
