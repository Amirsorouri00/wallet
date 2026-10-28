<?php

namespace Tests\Unit\Wallet;

use App\Domain\Wallet\DepositStatus;
use App\Domain\Wallet\DepositTransition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DepositStatusTest extends TestCase
{
    #[DataProvider('validTerminalTransitions')]
    public function test_pending_can_apply_a_terminal_transition(DepositStatus $next): void
    {
        self::assertSame(DepositTransition::APPLY, DepositStatus::PENDING->transitionTo($next));
    }

    /** @return array<string, array{DepositStatus}> */
    public static function validTerminalTransitions(): array
    {
        return [
            'succeeded' => [DepositStatus::SUCCEEDED],
            'failed' => [DepositStatus::FAILED],
        ];
    }

    #[DataProvider('sameStateReplays')]
    public function test_same_state_replay_is_a_noop(DepositStatus $status): void
    {
        self::assertSame(DepositTransition::NOOP, $status->transitionTo($status));
    }

    /** @return array<string, array{DepositStatus}> */
    public static function sameStateReplays(): array
    {
        return [
            'pending' => [DepositStatus::PENDING],
            'succeeded' => [DepositStatus::SUCCEEDED],
            'failed' => [DepositStatus::FAILED],
        ];
    }

    #[DataProvider('conflictingTerminalTransitions')]
    public function test_opposite_terminal_transition_conflicts(DepositStatus $current, DepositStatus $next): void
    {
        self::assertSame(DepositTransition::CONFLICT, $current->transitionTo($next));
    }

    /** @return array<string, array{DepositStatus, DepositStatus}> */
    public static function conflictingTerminalTransitions(): array
    {
        return [
            'succeeded to failed' => [DepositStatus::SUCCEEDED, DepositStatus::FAILED],
            'failed to succeeded' => [DepositStatus::FAILED, DepositStatus::SUCCEEDED],
            'succeeded to pending' => [DepositStatus::SUCCEEDED, DepositStatus::PENDING],
            'failed to pending' => [DepositStatus::FAILED, DepositStatus::PENDING],
        ];
    }
}
