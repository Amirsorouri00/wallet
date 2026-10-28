<?php

namespace App\Http\Errors;

use App\Domain\Wallet\CommandError;
use App\Exceptions\Wallet\CallbackConflict;
use App\Exceptions\Wallet\IdempotencyConflict;
use App\Exceptions\Wallet\WithdrawalConflict;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final class ProblemDetailsFactory
{
    /** @var list<string> */
    private const array RETRYABLE_SQLSTATES = ['40P01', '40001', '55P03', '57014'];

    public function forCommandError(CommandError $error, Request $request): JsonResponse
    {
        return match ($error) {
            CommandError::INSUFFICIENT_FUNDS => $this->response(ApiErrorCode::INSUFFICIENT_FUNDS, $request),
        };
    }

    public function fromException(Throwable $exception, Request $request): JsonResponse
    {
        [$code, $errors, $retryAfter] = match (true) {
            $exception instanceof ValidationException => [
                ApiErrorCode::VALIDATION_FAILED,
                $exception->errors(),
                null,
            ],
            $exception instanceof AccessDeniedHttpException => [ApiErrorCode::GATEWAY_FORBIDDEN, null, null],
            $exception instanceof NotFoundHttpException => [ApiErrorCode::RESOURCE_NOT_FOUND, null, null],
            $exception instanceof IdempotencyConflict => [ApiErrorCode::IDEMPOTENCY_CONFLICT, null, null],
            $exception instanceof CallbackConflict => [ApiErrorCode::CALLBACK_CONFLICT, null, null],
            $exception instanceof WithdrawalConflict => [ApiErrorCode::WITHDRAWAL_CONFLICT, null, null],
            $exception instanceof ThrottleRequestsException => [ApiErrorCode::RATE_LIMIT_EXCEEDED, null, 1],
            $exception instanceof BadRequestHttpException => [ApiErrorCode::MALFORMED_REQUEST, null, null],
            $exception instanceof InvalidArgumentException => [
                ApiErrorCode::VALIDATION_FAILED,
                ['request' => [$exception->getMessage()]],
                null,
            ],
            $this->isRetryableQueryFailure($exception) => [ApiErrorCode::DATABASE_BUSY, null, 1],
            default => [ApiErrorCode::INTERNAL_ERROR, null, null],
        };

        return $this->response($code, $request, $errors, $retryAfter);
    }

    /** @param array<string, mixed>|null $errors */
    public function response(
        ApiErrorCode $code,
        Request $request,
        ?array $errors = null,
        ?int $retryAfter = null,
    ): JsonResponse {
        $requestId = (string) $request->attributes->get('request_id', 'unknown');
        $baseUri = rtrim((string) config('wallet.problem_base_uri'), '/');
        $body = [
            'type' => $baseUri.'/'.$code->slug(),
            'title' => $code->title(),
            'status' => $code->status(),
            'detail' => $code->detail(),
            'instance' => 'urn:request:'.$requestId,
            'code' => $code->value,
        ];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        if (in_array($code, [
            ApiErrorCode::RATE_LIMIT_EXCEEDED,
            ApiErrorCode::DATABASE_BUSY,
            ApiErrorCode::SERVICE_NOT_READY,
        ], true)) {
            $body['retryable'] = true;
        }

        $headers = [
            'Content-Type' => 'application/problem+json',
            'X-Request-Id' => $requestId,
        ];

        if ($retryAfter !== null) {
            $headers['Retry-After'] = (string) $retryAfter;
        }

        return response()->json($body, $code->status(), $headers, JSON_UNESCAPED_SLASHES);
    }

    private function isRetryableQueryFailure(Throwable $exception): bool
    {
        return $exception instanceof QueryException
            && in_array($exception->errorInfo[0] ?? null, self::RETRYABLE_SQLSTATES, true);
    }
}
