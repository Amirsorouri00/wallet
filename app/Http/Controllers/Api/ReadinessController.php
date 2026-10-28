<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Errors\ApiErrorCode;
use App\Http\Errors\ProblemDetailsFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ReadinessController extends Controller
{
    public function __invoke(Request $request, ProblemDetailsFactory $problems): JsonResponse
    {
        try {
            DB::selectOne('SELECT 1');

            if (! $this->schemaIsCompatible()) {
                return $this->notReady($problems, $request, 'Required database schema is missing or incompatible.');
            }

            if (! $this->systemWalletsAreProvisioned()) {
                return $this->notReady($problems, $request, 'Required clearing or withdrawal suspense wallets are missing.');
            }

            $lag = $this->oldestDueOutboxLag();
            $maximumLag = (int) config('wallet.readiness.max_outbox_lag_seconds');

            if ($lag > $maximumLag) {
                return $this->notReady($problems, $request, 'Transactional outbox lag exceeds the readiness threshold.');
            }

            return response()->json([
                'status' => 'ready',
                'checks' => [
                    'database' => 'ok',
                    'schema' => 'ok',
                    'system_wallets' => 'ok',
                    'outbox' => 'ok',
                ],
                'outbox_lag_seconds' => $lag,
            ]);
        } catch (Throwable) {
            return $this->notReady($problems, $request, 'Database readiness checks could not complete.');
        }
    }

    private function schemaIsCompatible(): bool
    {
        return Schema::hasColumns('wallets', ['id', 'owner_type', 'owner_id'])
            && Schema::hasColumns('ledger_transactions', ['id', 'type', 'reference_type'])
            && Schema::hasColumns('wallet_balance_states', ['wallet_id', 'version', 'balance_after_minor'])
            && Schema::hasColumns('outbox_messages', ['available_at', 'leased_until', 'published_at', 'dead_lettered_at']);
    }

    private function systemWalletsAreProvisioned(): bool
    {
        $clearing = DB::table('wallets')
            ->where('owner_type', 'system')
            ->where('owner_id', 'like', 'gateway-clearing-%')
            ->count();
        $suspense = DB::table('wallets')
            ->where('owner_type', 'system')
            ->where('owner_id', 'like', 'withdrawal-suspense-%')
            ->count();

        return $clearing === (int) config('wallet.clearing_wallet_count')
            && $suspense === (int) config('wallet.withdrawal_suspense_wallet_count');
    }

    private function oldestDueOutboxLag(): int
    {
        return (int) DB::selectOne(<<<'SQL'
            SELECT COALESCE(EXTRACT(EPOCH FROM (
                CURRENT_TIMESTAMP - MIN(occurred_at)
            )), 0)::integer AS seconds
            FROM outbox_messages
            WHERE published_at IS NULL
              AND dead_lettered_at IS NULL
              AND available_at <= CURRENT_TIMESTAMP
              AND (leased_until IS NULL OR leased_until <= CURRENT_TIMESTAMP)
            SQL)->seconds;
    }

    private function notReady(
        ProblemDetailsFactory $problems,
        Request $request,
        string $reason,
    ): JsonResponse {
        return $problems->response(
            ApiErrorCode::SERVICE_NOT_READY,
            $request,
            ['checks' => [$reason]],
            retryAfter: 1,
        );
    }
}
