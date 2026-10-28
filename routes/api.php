<?php

use App\Http\Controllers\Api\DepositController;
use App\Http\Controllers\Api\GatewayDepositCallbackController;
use App\Http\Controllers\Api\ReadinessController;
use App\Http\Controllers\Api\TransferController;
use App\Http\Controllers\Api\WalletHistoryController;
use App\Http\Controllers\Api\WithdrawalAdminController;
use App\Http\Controllers\Api\WithdrawalController;
use Illuminate\Support\Facades\Route;

Route::get('/health', static fn () => response()->json([
    'status' => 'ok',
]))->name('health');
Route::get('/ready', ReadinessController::class)->name('ready');

Route::prefix('v1')->name('v1.')->group(function (): void {
    Route::post('/wallets/{wallet}/deposits', [DepositController::class, 'store'])
        ->middleware('throttle:wallet-writes')->name('wallets.deposits.store');
    Route::get('/deposits/{deposit}', [DepositController::class, 'show'])
        ->middleware('throttle:wallet-reads')->name('deposits.show');
    Route::post('/gateway/deposits/{deposit}/confirm', [GatewayDepositCallbackController::class, 'confirm'])
        ->middleware('throttle:gateway-callbacks')->name('gateway.deposits.confirm');
    Route::post('/gateway/deposits/{deposit}/fail', [GatewayDepositCallbackController::class, 'fail'])
        ->middleware('throttle:gateway-callbacks')->name('gateway.deposits.fail');
    Route::post('/wallets/{wallet}/transfers', [TransferController::class, 'store'])
        ->middleware('throttle:wallet-writes')->name('wallets.transfers.store');
    Route::get('/wallets/{wallet}/transactions', [WalletHistoryController::class, 'index'])
        ->middleware('throttle:wallet-reads')->name('wallets.transactions.index');
    Route::post('/wallets/{wallet}/withdrawals', [WithdrawalController::class, 'store'])
        ->middleware('throttle:wallet-writes')->name('wallets.withdrawals.store');
    Route::get('/withdrawals/{withdrawal}', [WithdrawalController::class, 'show'])
        ->middleware('throttle:wallet-reads')->name('withdrawals.show');
    Route::post('/admin/withdrawals/{withdrawal}/approve', [WithdrawalAdminController::class, 'approve'])
        ->middleware('throttle:admin-commands')->name('admin.withdrawals.approve');
    Route::post('/admin/withdrawals/{withdrawal}/settle', [WithdrawalAdminController::class, 'settle'])
        ->middleware('throttle:admin-commands')->name('admin.withdrawals.settle');
    Route::post('/admin/withdrawals/{withdrawal}/reject', [WithdrawalAdminController::class, 'reject'])
        ->middleware('throttle:admin-commands')->name('admin.withdrawals.reject');
});
