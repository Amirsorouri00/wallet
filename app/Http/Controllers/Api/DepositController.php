<?php

namespace App\Http\Controllers\Api;

use App\Actions\Wallet\CreateDeposit;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateDepositRequest;
use App\Http\Resources\DepositResource;
use App\Http\Responses\CommandResultResponse;
use App\Models\Deposit;
use App\Models\Wallet;
use Dedoc\Scramble\Attributes\IgnoreResponse;
use Illuminate\Http\JsonResponse;

class DepositController extends Controller
{
    #[IgnoreResponse(200)]
    public function store(
        CreateDepositRequest $request,
        Wallet $wallet,
        CreateDeposit $action,
        CommandResultResponse $responses,
    ): JsonResponse {
        $response = $action->handle(
            $wallet->id,
            $request->amountMinor(),
            $request->idempotencyKey(),
        );

        return $responses->make($response, $request);
    }

    public function show(Deposit $deposit): DepositResource
    {
        return new DepositResource($deposit);
    }
}
