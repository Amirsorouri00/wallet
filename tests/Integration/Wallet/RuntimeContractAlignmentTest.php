<?php

namespace Tests\Integration\Wallet;

use App\Domain\Wallet\LedgerReferenceType;
use App\Domain\Wallet\LedgerTransactionType;
use App\Domain\Wallet\OperationType;
use App\Domain\Wallet\OutboxAggregateType;
use App\Domain\Wallet\OutboxEventType;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RuntimeContractAlignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_enums_match_their_postgresql_check_constraints(): void
    {
        $contracts = [
            'ledger_transactions_type_check' => LedgerTransactionType::cases(),
            'ledger_transactions_reference_type_check' => LedgerReferenceType::cases(),
            'idempotency_records_operation_check' => OperationType::cases(),
            'outbox_messages_event_type_check' => OutboxEventType::cases(),
            'outbox_messages_aggregate_type_check' => OutboxAggregateType::cases(),
        ];

        foreach ($contracts as $constraint => $cases) {
            $expected = array_map(static fn ($case): string => $case->value, $cases);
            sort($expected);

            self::assertSame($expected, $this->constraintValues($constraint), $constraint);
        }
    }

    public function test_withdrawal_model_keeps_the_contracted_table_name(): void
    {
        self::assertSame('withdrawal_requests', (new Withdrawal)->getTable());
    }

    /** @return list<string> */
    private function constraintValues(string $constraint): array
    {
        $definition = DB::selectOne(
            'SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conname = ?',
            [$constraint],
        )?->definition;

        self::assertIsString($definition, "Missing PostgreSQL constraint {$constraint}.");
        preg_match_all("/'([^']+)'/", $definition, $matches);
        $values = array_values(array_unique($matches[1]));
        sort($values);

        return $values;
    }
}
