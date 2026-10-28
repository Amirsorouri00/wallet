<?php

namespace App\Http\Controllers\Api;

use App\Actions\Wallet\CreateWithdrawal;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateWithdrawalRequest;
use App\Http\Resources\WithdrawalResource;
use App\Http\Responses\CommandResultResponse;
use App\Models\Wallet;
use App\Models\Withdrawal;
use Dedoc\Scramble\Attributes\IgnoreResponse;
use Illuminate\Http\JsonResponse;

class WithdrawalController extends Controller
{
    #[IgnoreResponse(200)]
    public function store(
        CreateWithdrawalRequest $request,
        Wallet $wallet,
        CreateWithdrawal $action,
        CommandResultResponse $responses,
    ): JsonResponse {
        $response = $action->handle($wallet->id, $request->amountMinor(), $request->idempotencyKey());

        return $responses->make($response, $request);
    }

    #[IgnoreResponse(200)]
    public function show(Withdrawal $withdrawal): WithdrawalResource
    {
        return new WithdrawalResource($withdrawal);
    }
}
