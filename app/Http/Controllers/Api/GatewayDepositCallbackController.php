<?php

namespace App\Http\Controllers\Api;

use App\Actions\Wallet\ConfirmDeposit;
use App\Actions\Wallet\FailDeposit;
use App\Data\Wallet\GatewayCallback;
use App\Http\Controllers\Controller;
use App\Http\Requests\GatewayCallbackRequest;
use App\Http\Responses\CommandResultResponse;
use App\Models\Deposit;
use Dedoc\Scramble\Attributes\IgnoreResponse;
use Illuminate\Http\JsonResponse;

class GatewayDepositCallbackController extends Controller
{
    #[IgnoreResponse(200)]
    public function confirm(
        GatewayCallbackRequest $request,
        Deposit $deposit,
        ConfirmDeposit $action,
        CommandResultResponse $responses,
    ): JsonResponse {
        $response = $action->handle($deposit->id, $this->callback($request));

        return $responses->make($response, $request);
    }

    #[IgnoreResponse(200)]
    public function fail(
        GatewayCallbackRequest $request,
        Deposit $deposit,
        FailDeposit $action,
        CommandResultResponse $responses,
    ): JsonResponse {
        $response = $action->handle($deposit->id, $this->callback($request));

        return $responses->make($response, $request);
    }

    private function callback(GatewayCallbackRequest $request): GatewayCallback
    {
        return new GatewayCallback($request->gatewayReference(), $request->amountMinor());
    }
}
