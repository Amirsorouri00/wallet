<?php

namespace Tests\Support;

use App\Actions\Wallet\ApproveWithdrawal;
use App\Actions\Wallet\RejectWithdrawal;
use App\Actions\Wallet\SettleWithdrawal;
use App\Exceptions\Wallet\WithdrawalConflict;
use App\Http\Responses\CommandResultResponse;
use Illuminate\Contracts\Console\Kernel;
use Throwable;

final class WithdrawalCommandWorker
{
    /** @param list<string> $arguments */
    public static function main(array $arguments): int
    {
        [, $action, $withdrawalId, $key, $reason, $ready, $barrier, $result] = $arguments;
        touch($ready);
        $deadline = microtime(true) + 15;

        while (! file_exists($barrier) && microtime(true) < $deadline) {
            usleep(10_000);
        }

        if (! file_exists($barrier)) {
            file_put_contents($result, json_encode(['error' => 'barrier_timeout'], JSON_THROW_ON_ERROR));

            return 2;
        }

        try {
            $response = match ($action) {
                'approve' => app(ApproveWithdrawal::class)->handle($withdrawalId, $key),
                'reject' => app(RejectWithdrawal::class)->handle($withdrawalId, $key, $reason),
                'settle' => app(SettleWithdrawal::class)->handle($withdrawalId, $key),
                default => throw new \InvalidArgumentException("Unknown withdrawal action: {$action}"),
            };
            file_put_contents($result, json_encode([
                'status' => CommandResultResponse::status($response),
                'body' => $response->body(),
                'successful' => $response->successful(),
            ], JSON_THROW_ON_ERROR));
        } catch (WithdrawalConflict $exception) {
            file_put_contents($result, json_encode([
                'status' => 409,
                'error' => $exception::class,
            ], JSON_THROW_ON_ERROR));
        } catch (Throwable $exception) {
            file_put_contents($result, json_encode([
                'status' => 500,
                'error' => $exception::class,
                'message' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR));
        }

        return 0;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    $application = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $application->make(Kernel::class)->bootstrap();

    exit(WithdrawalCommandWorker::main($argv));
}
