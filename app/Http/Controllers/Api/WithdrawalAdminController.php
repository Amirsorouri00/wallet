<?php

namespace App\Http\Controllers\Api;

use App\Actions\Wallet\ApproveWithdrawal;
use App\Actions\Wallet\RejectWithdrawal;
use App\Actions\Wallet\SettleWithdrawal;
use App\Http\Controllers\Controller;
use App\Http\Requests\RejectWithdrawalRequest;
use App\Http\Requests\WithdrawalCommandRequest;
use App\Http\Responses\CommandResultResponse;
use App\Models\Withdrawal;
use Dedoc\Scramble\Attributes\IgnoreResponse;
use Illuminate\Http\JsonResponse;

class WithdrawalAdminController extends Controller
{
    #[IgnoreResponse(200)]
    public function approve(
        WithdrawalCommandRequest $request,
        Withdrawal $withdrawal,
        ApproveWithdrawal $action,
        CommandResultResponse $responses,
    ): JsonResponse {
        $response = $action->handle($withdrawal->id, $request->idempotencyKey());

        return $responses->make($response, $request);
    }

    #[IgnoreResponse(200)]
    public function settle(
        WithdrawalCommandRequest $request,
        Withdrawal $withdrawal,
        SettleWithdrawal $action,
        CommandResultResponse $responses,
    ): JsonResponse {
        $response = $action->handle($withdrawal->id, $request->idempotencyKey());

        return $responses->make($response, $request);
    }

    #[IgnoreResponse(200)]
    public function reject(
        RejectWithdrawalRequest $request,
        Withdrawal $withdrawal,
        RejectWithdrawal $action,
        CommandResultResponse $responses,
    ): JsonResponse {
        $response = $action->handle($withdrawal->id, $request->idempotencyKey(), $request->reason());

        return $responses->make($response, $request);
    }
}
