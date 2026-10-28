<?php

namespace App\Console\Commands;

use App\Actions\Wallet\ApproveWithdrawal;
use App\Actions\Wallet\ConfirmDeposit;
use App\Actions\Wallet\CreateDeposit;
use App\Actions\Wallet\CreateWithdrawal;
use App\Actions\Wallet\SettleWithdrawal;
use App\Actions\Wallet\TransferFunds;
use App\Data\Wallet\GatewayCallback;
use App\Exceptions\Wallet\IdempotencyConflict;
use App\Services\Wallet\WalletProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WalletDemoScenario extends Command
{
    protected $signature = 'wallet:demo-scenario';

    protected $description = 'Run an isolated deposit, retry, transfer, and withdrawal walkthrough';

    public function handle(
        WalletProvisioner $wallets,
        CreateDeposit $createDeposit,
        ConfirmDeposit $confirmDeposit,
        TransferFunds $transferFunds,
        CreateWithdrawal $createWithdrawal,
        ApproveWithdrawal $approveWithdrawal,
        SettleWithdrawal $settleWithdrawal,
    ): int {
        $runId = (string) Str::uuid7();
        $source = $wallets->create('user', "demo-scenario-{$runId}-source");
        $destination = $wallets->create('user', "demo-scenario-{$runId}-destination");

        $created = $createDeposit->handle($source->id, 10_000, "{$runId}-deposit");
        $replayed = $createDeposit->handle($source->id, 10_000, "{$runId}-deposit");
        $deposit = $created->body()['data'];

        $this->line('Identical deposit replay: '.($created->body() === $replayed->body() ? 'same response' : 'FAILED'));

        try {
            $createDeposit->handle($source->id, 10_001, "{$runId}-deposit");
            $this->error('Conflicting deposit replay: unexpectedly accepted');

            return self::FAILURE;
        } catch (IdempotencyConflict) {
            $this->line('Conflicting deposit replay: IDEMPOTENCY_CONFLICT');
        }

        $confirmDeposit->handle(
            $deposit['deposit_id'],
            new GatewayCallback($deposit['gateway_reference'], 10_000),
        );

        $transfer = $transferFunds->handle(
            $source->id,
            $destination->id,
            2_500,
            "{$runId}-transfer",
        );
        $withdrawal = $createWithdrawal
            ->handle($source->id, 1_500, "{$runId}-withdrawal")
            ->body()['data'];
        $approved = $approveWithdrawal->handle($withdrawal['withdrawal_id'], "{$runId}-approve");
        $settled = $settleWithdrawal->handle($withdrawal['withdrawal_id'], "{$runId}-settle");

        $this->newLine();
        $this->table(
            ['Step', 'Status', 'Amount minor'],
            [
                ['deposit', 'SUCCEEDED', '10000'],
                ['transfer', $transfer->body()['data']['transfer_id'], '2500'],
                ['withdrawal approval', $approved->body()['data']['status'], '1500'],
                ['withdrawal settlement', $settled->body()['data']['status'], '1500'],
            ],
        );

        $states = DB::table('wallet_balance_states')
            ->whereIn('wallet_id', [$source->id, $destination->id])
            ->where('version', '>', 0)
            ->orderBy('wallet_id')
            ->orderBy('version')
            ->get(['wallet_id', 'version', 'delta_minor', 'balance_before_minor', 'balance_after_minor'])
            ->map(fn (object $state): array => (array) $state)
            ->all();

        $this->line('Source wallet: '.$source->id);
        $this->line('Destination wallet: '.$destination->id);
        $this->table(
            ['Wallet', 'Version', 'Delta', 'Before', 'After'],
            $states,
        );

        $reconciliationExit = Artisan::call('wallets:reconcile', ['--fail-on-drift' => true]);
        $this->line('Reconciliation: '.($reconciliationExit === self::SUCCESS ? 'clean' : 'DRIFT DETECTED'));

        return $reconciliationExit === self::SUCCESS ? self::SUCCESS : self::FAILURE;
    }
}
