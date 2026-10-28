<?php

namespace App\Http\Controllers\Api;

use App\Actions\Wallet\ListWalletHistory;
use App\Http\Controllers\Controller;
use App\Http\Requests\WalletHistoryRequest;
use App\Http\Resources\LedgerHistoryResource;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;

class WalletHistoryController extends Controller
{
    public function index(
        WalletHistoryRequest $request,
        Wallet $wallet,
        ListWalletHistory $action,
    ): JsonResponse {
        $page = $action->handle(
            $wallet->id,
            $request->filters(),
            $request->cursor(),
            $request->perPage(),
        );

        return response()->json([
            'data' => LedgerHistoryResource::collection($page->items())->resolve($request),
            'meta' => [
                'next_cursor' => $page->nextCursor(),
                'per_page' => $page->perPage(),
            ],
        ]);
    }
}
