<?php

namespace App\Http\Controllers\Api;

use App\Actions\Wallet\TransferFunds;
use App\Http\Controllers\Controller;
use App\Http\Requests\TransferRequest;
use App\Http\Responses\CommandResultResponse;
use App\Models\Wallet;
use Dedoc\Scramble\Attributes\IgnoreResponse;
use Illuminate\Http\JsonResponse;

class TransferController extends Controller
{
    #[IgnoreResponse(200)]
    public function store(
        TransferRequest $request,
        Wallet $wallet,
        TransferFunds $action,
        CommandResultResponse $responses,
    ): JsonResponse {
        $response = $action->handle(
            $wallet->id,
            $request->destinationWalletId(),
            $request->amountMinor(),
            $request->idempotencyKey(),
        );

        return $responses->make($response, $request);
    }
}
