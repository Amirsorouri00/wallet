<?php

namespace App\OpenApi;

use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

final class WalletApiDocumentTransformer implements DocumentTransformer
{
    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        $this->addProblemDetails($document);
        $this->addCommandResponses($document);
    }

    private function addProblemDetails(OpenApi $document): void
    {
        if ($document->components->hasSchema('ProblemDetails')) {
            return;
        }

        $validationErrors = (new ObjectType)
            ->additionalProperties((new ArrayType)->setItems(new StringType));

        $problem = (new ObjectType)
            ->addProperty('type', (new StringType)->format('uri-reference'))
            ->addProperty('title', new StringType)
            ->addProperty('status', (new IntegerType)->setMin(400)->setMax(599))
            ->addProperty('detail', new StringType)
            ->addProperty('instance', (new StringType)->format('uri-reference'))
            ->addProperty('code', new StringType)
            ->addProperty('errors', $validationErrors)
            ->addProperty('retryable', new BooleanType)
            ->setRequired(['type', 'title', 'status', 'detail', 'instance', 'code']);

        $document->components->addSchema('ProblemDetails', Schema::fromType($problem));
    }

    private function addCommandResponses(OpenApi $document): void
    {
        $document->components->addSchema('DepositCommandResponse', $this->envelope(
            (new ObjectType)
                ->addProperty('version', new IntegerType)
                ->addProperty('deposit_id', $this->uuid())
                ->addProperty('wallet_id', $this->uuid())
                ->addProperty('clearing_wallet_id', $this->uuid())
                ->addProperty('amount_minor', (new IntegerType)->setMin(1))
                ->addProperty('status', (new StringType)->enum(['PENDING', 'SUCCEEDED', 'FAILED']))
                ->addProperty('gateway_reference', $this->uuid())
                ->addProperty('ledger_transaction_id', $this->nullableUuid())
                ->addProperty('failure_reason', (new StringType)->nullable(true))
                ->setRequired([
                    'version', 'deposit_id', 'wallet_id', 'clearing_wallet_id',
                    'amount_minor', 'status', 'gateway_reference',
                ]),
        ));

        $document->components->addSchema('TransferCommandResponse', $this->envelope(
            (new ObjectType)
                ->addProperty('version', new IntegerType)
                ->addProperty('transfer_id', $this->uuid())
                ->addProperty('ledger_transaction_id', $this->uuid())
                ->addProperty('source_wallet_id', $this->uuid())
                ->addProperty('destination_wallet_id', $this->uuid())
                ->addProperty('amount_minor', (new IntegerType)->setMin(1))
                ->setRequired([
                    'version', 'transfer_id', 'ledger_transaction_id',
                    'source_wallet_id', 'destination_wallet_id', 'amount_minor',
                ]),
        ));

        $document->components->addSchema('WithdrawalCommandResponse', $this->envelope(
            (new ObjectType)
                ->addProperty('version', new IntegerType)
                ->addProperty('withdrawal_id', $this->uuid())
                ->addProperty('wallet_id', $this->uuid())
                ->addProperty('suspense_wallet_id', $this->uuid())
                ->addProperty('amount_minor', (new IntegerType)->setMin(1))
                ->addProperty('status', (new StringType)->enum(['PENDING', 'APPROVED', 'SETTLED', 'REJECTED']))
                ->addProperty('approval_ledger_transaction_id', $this->nullableUuid())
                ->addProperty('compensation_ledger_transaction_id', $this->nullableUuid())
                ->addProperty('rejection_reason', (new StringType)->nullable(true))
                ->addProperty('approved_at', $this->nullableTimestamp())
                ->addProperty('settled_at', $this->nullableTimestamp())
                ->addProperty('rejected_at', $this->nullableTimestamp())
                ->addProperty('created_at', $this->nullableTimestamp())
                ->setRequired([
                    'version', 'withdrawal_id', 'wallet_id', 'suspense_wallet_id',
                    'amount_minor', 'status', 'approval_ledger_transaction_id',
                    'compensation_ledger_transaction_id', 'rejection_reason',
                    'approved_at', 'settled_at', 'rejected_at', 'created_at',
                ]),
        ));
    }

    private function envelope(ObjectType $payload): Schema
    {
        return Schema::fromType(
            (new ObjectType)
                ->addProperty('data', $payload)
                ->setRequired(['data']),
        );
    }

    private function uuid(): StringType
    {
        return (new StringType)->format('uuid');
    }

    private function nullableUuid(): StringType
    {
        return $this->uuid()->nullable(true);
    }

    private function nullableTimestamp(): StringType
    {
        return (new StringType)->format('date-time')->nullable(true);
    }
}
