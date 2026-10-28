<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class LogWalletRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            Log::error('wallet-request-failed', $this->context(
                $request,
                $startedAt,
                httpStatus: 500,
                outcome: 'failure',
                sqlState: $exception instanceof QueryException ? ($exception->errorInfo[0] ?? null) : null,
            ));

            throw $exception;
        }

        Log::info('wallet-request', $this->context(
            $request,
            $startedAt,
            $response->getStatusCode(),
            $response->getStatusCode() < 400 ? 'success' : ($response->getStatusCode() < 500 ? 'rejected' : 'failure'),
            transactionId: $this->transactionId($response),
        ));

        return $response;
    }

    /** @return array<string, int|float|string|null> */
    private function context(
        Request $request,
        int $startedAt,
        int $httpStatus,
        string $outcome,
        ?string $sqlState = null,
        ?string $transactionId = null,
    ): array {
        return [
            'request_id' => (string) $request->attributes->get('request_id', 'unknown'),
            'operation' => $request->route()?->getName() ?? 'unmatched',
            'transaction_id' => $transactionId,
            'wallet_id' => $this->walletId($request),
            'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 3),
            'retry_count' => 0,
            'outcome' => $outcome,
            'http_status' => $httpStatus,
            'sqlstate' => $sqlState,
        ];
    }

    private function walletId(Request $request): ?string
    {
        $wallet = $request->route('wallet');

        if (is_object($wallet) && isset($wallet->id)) {
            return (string) $wallet->id;
        }

        return is_scalar($wallet) ? (string) $wallet : null;
    }

    private function transactionId(Response $response): ?string
    {
        if (! $response instanceof JsonResponse) {
            return null;
        }

        $data = $response->getData(true)['data'] ?? null;

        return is_array($data) && isset($data['ledger_transaction_id'])
            ? (string) $data['ledger_transaction_id']
            : null;
    }
}
