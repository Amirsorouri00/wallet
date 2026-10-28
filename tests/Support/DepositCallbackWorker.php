<?php

namespace Tests\Support;

use App\Actions\Wallet\ConfirmDeposit;
use App\Actions\Wallet\FailDeposit;
use App\Data\Wallet\GatewayCallback;
use App\Exceptions\Wallet\CallbackConflict;
use App\Http\Responses\CommandResultResponse;
use Illuminate\Contracts\Console\Kernel;
use Throwable;

final class DepositCallbackWorker
{
    /** @param list<string> $arguments */
    public static function main(array $arguments): int
    {
        [, $action, $depositId, $reference, $amount, $ready, $barrier, $result] = $arguments;
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
            $callback = new GatewayCallback($reference, (int) $amount);
            $handler = $action === 'confirm'
                ? app(ConfirmDeposit::class)
                : app(FailDeposit::class);
            $response = $handler->handle($depositId, $callback);
            file_put_contents($result, json_encode([
                'status' => CommandResultResponse::status($response),
                'body' => $response->body(),
                'successful' => $response->successful(),
            ], JSON_THROW_ON_ERROR));

            return 0;
        } catch (CallbackConflict $exception) {
            file_put_contents($result, json_encode([
                'status' => 409,
                'error' => $exception::class,
                'message' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR));

            return 0;
        } catch (Throwable $exception) {
            file_put_contents($result, json_encode([
                'status' => 500,
                'error' => $exception::class,
                'message' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR));

            return 0;
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    $application = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $application->make(Kernel::class)->bootstrap();

    exit(DepositCallbackWorker::main($argv));
}
