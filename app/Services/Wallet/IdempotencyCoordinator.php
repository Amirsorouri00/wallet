<?php

namespace App\Services\Wallet;

use App\Data\Wallet\CommandResult;
use App\Domain\Wallet\CommandError;
use App\Domain\Wallet\CommandOutcome;
use App\Domain\Wallet\IdempotencyKey;
use App\Domain\Wallet\OperationType;
use App\Exceptions\Wallet\IdempotencyConflict;
use App\Models\IdempotencyRecord;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

final class IdempotencyCoordinator
{
    /**
     * @param  array<string, mixed>  $normalizedRequest
     * @param  Closure(string): CommandResult  $databaseOnlyOperation
     */
    public function execute(
        string $walletId,
        OperationType $operation,
        IdempotencyKey $key,
        array $normalizedRequest,
        Closure $databaseOnlyOperation,
    ): CommandResult {
        $requestHash = self::fingerprint($normalizedRequest);

        try {
            return DB::transaction(function () use (
                $walletId,
                $operation,
                $key,
                $requestHash,
                $databaseOnlyOperation,
            ): CommandResult {
                $record = IdempotencyRecord::query()->create([
                    'actor_wallet_id' => $walletId,
                    'operation' => $operation->value,
                    'idempotency_key' => $key->value(),
                    'request_hash' => $requestHash,
                    'result_status' => 'processing',
                ]);

                $operationResponse = $databaseOnlyOperation($record->id);
                $response = match ($operationResponse->outcome()) {
                    CommandOutcome::CREATED => CommandResult::created(self::canonicalize($operationResponse->body())),
                    CommandOutcome::SUCCEEDED => CommandResult::succeeded(self::canonicalize($operationResponse->body())),
                    CommandOutcome::REJECTED => CommandResult::rejected($operationResponse->requiredError()),
                };
                $storedBody = $response->successful()
                    ? self::canonicalize($response->body())
                    : ['error_code' => $response->requiredError()->value];

                $record->forceFill([
                    'result_status' => $response->successful() ? 'succeeded' : 'rejected',
                    'http_status' => self::statusFor($response->outcome()),
                    'response_body' => $storedBody,
                ])->save();

                return $response;
            }, attempts: (int) config('wallet.deadlock_retries'));
        } catch (QueryException $exception) {
            if (! $this->isReservationConflict($exception)) {
                throw $exception;
            }
        }

        return DB::transaction(function () use ($walletId, $operation, $key, $requestHash): CommandResult {
            $record = IdempotencyRecord::query()
                ->where('actor_wallet_id', $walletId)
                ->where('operation', $operation->value)
                ->where('idempotency_key', $key->value())
                ->lockForUpdate()
                ->firstOrFail();

            if (! hash_equals($record->request_hash, $requestHash)) {
                throw new IdempotencyConflict('The idempotency key was already used with a different request.');
            }

            if ($record->result_status === 'processing') {
                throw new LogicException('A committed idempotency reservation has no terminal response.');
            }

            if ($record->result_status === 'rejected') {
                return CommandResult::rejected(CommandError::from($record->response_body['error_code']));
            }

            $body = self::canonicalize($record->response_body);

            return $record->http_status === 201
                ? CommandResult::created($body)
                : CommandResult::succeeded($body);
        }, attempts: (int) config('wallet.deadlock_retries'));
    }

    /** @param array<string, mixed> $request */
    public static function fingerprint(array $request): string
    {
        return hash('sha256', json_encode(
            self::canonicalize($request),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function isReservationConflict(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23505'
            && str_contains($exception->getMessage(), 'idempotency_records_scope_unique');
    }

    private static function statusFor(CommandOutcome $outcome): int
    {
        return match ($outcome) {
            CommandOutcome::CREATED => 201,
            CommandOutcome::SUCCEEDED => 200,
            CommandOutcome::REJECTED => 422,
        };
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }
}
