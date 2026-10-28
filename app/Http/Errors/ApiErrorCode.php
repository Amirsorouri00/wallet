<?php

namespace App\Http\Errors;

enum ApiErrorCode: string
{
    case MALFORMED_REQUEST = 'MALFORMED_REQUEST';
    case VALIDATION_FAILED = 'VALIDATION_FAILED';
    case GATEWAY_FORBIDDEN = 'GATEWAY_FORBIDDEN';
    case RESOURCE_NOT_FOUND = 'RESOURCE_NOT_FOUND';
    case IDEMPOTENCY_CONFLICT = 'IDEMPOTENCY_CONFLICT';
    case CALLBACK_CONFLICT = 'CALLBACK_CONFLICT';
    case WITHDRAWAL_CONFLICT = 'WITHDRAWAL_CONFLICT';
    case INSUFFICIENT_FUNDS = 'INSUFFICIENT_FUNDS';
    case RATE_LIMIT_EXCEEDED = 'RATE_LIMIT_EXCEEDED';
    case DATABASE_BUSY = 'DATABASE_BUSY';
    case SERVICE_NOT_READY = 'SERVICE_NOT_READY';
    case INTERNAL_ERROR = 'INTERNAL_ERROR';

    public function status(): int
    {
        return match ($this) {
            self::MALFORMED_REQUEST => 400,
            self::GATEWAY_FORBIDDEN => 403,
            self::RESOURCE_NOT_FOUND => 404,
            self::IDEMPOTENCY_CONFLICT,
            self::CALLBACK_CONFLICT,
            self::WITHDRAWAL_CONFLICT => 409,
            self::VALIDATION_FAILED,
            self::INSUFFICIENT_FUNDS => 422,
            self::RATE_LIMIT_EXCEEDED => 429,
            self::DATABASE_BUSY => 503,
            self::SERVICE_NOT_READY => 503,
            self::INTERNAL_ERROR => 500,
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::MALFORMED_REQUEST => 'Malformed request',
            self::VALIDATION_FAILED => 'Validation failed',
            self::GATEWAY_FORBIDDEN => 'Gateway access denied',
            self::RESOURCE_NOT_FOUND => 'Resource not found',
            self::IDEMPOTENCY_CONFLICT => 'Idempotency conflict',
            self::CALLBACK_CONFLICT => 'Callback conflict',
            self::WITHDRAWAL_CONFLICT => 'Withdrawal conflict',
            self::INSUFFICIENT_FUNDS => 'Insufficient funds',
            self::RATE_LIMIT_EXCEEDED => 'Rate limit exceeded',
            self::DATABASE_BUSY => 'Database temporarily busy',
            self::SERVICE_NOT_READY => 'Service not ready',
            self::INTERNAL_ERROR => 'Internal server error',
        };
    }

    public function detail(): string
    {
        return match ($this) {
            self::MALFORMED_REQUEST => 'The request body is not valid JSON.',
            self::VALIDATION_FAILED => 'The request data is invalid.',
            self::GATEWAY_FORBIDDEN => 'The gateway credential is invalid.',
            self::RESOURCE_NOT_FOUND => 'The requested resource was not found.',
            self::IDEMPOTENCY_CONFLICT => 'The idempotency key was already used with a different request.',
            self::CALLBACK_CONFLICT => 'The callback conflicts with the current deposit state or identity.',
            self::WITHDRAWAL_CONFLICT => 'The command conflicts with the current withdrawal state.',
            self::INSUFFICIENT_FUNDS => 'The wallet does not have enough available funds.',
            self::RATE_LIMIT_EXCEEDED => 'Too many requests were received. Retry later.',
            self::DATABASE_BUSY => 'The operation could not acquire the required database resources. Retry later.',
            self::SERVICE_NOT_READY => 'The service cannot safely accept traffic yet. Retry later.',
            self::INTERNAL_ERROR => 'An unexpected error occurred.',
        };
    }

    public function slug(): string
    {
        return strtolower(str_replace('_', '-', $this->value));
    }
}
