<?php

namespace App\OpenApi;

use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;

final class WalletApiOperationTransformer implements OperationTransformer
{
    private const array IDEMPOTENT_ROUTES = [
        'v1.wallets.deposits.store',
        'v1.wallets.transfers.store',
        'v1.wallets.withdrawals.store',
        'v1.admin.withdrawals.approve',
        'v1.admin.withdrawals.settle',
        'v1.admin.withdrawals.reject',
    ];

    private const array GATEWAY_ROUTES = [
        'v1.gateway.deposits.confirm',
        'v1.gateway.deposits.fail',
    ];

    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $routeName = (string) $routeInfo->route->getName();

        $operation->setOperationId(str_replace('.', '_', $routeName));
        $this->replaceDynamicCommandResponse($operation, $routeName);

        if (in_array($routeName, self::IDEMPOTENT_ROUTES, true)) {
            $operation->addParameters([$this->idempotencyKeyParameter()]);
        }

        if (in_array($routeName, self::GATEWAY_ROUTES, true)) {
            $operation->addParameters([$this->gatewaySecretParameter()]);
        }

        foreach ($this->errorStatuses($routeName) as $status) {
            $operation->addResponse($this->problemResponse($status));
        }
    }

    private function replaceDynamicCommandResponse(Operation $operation, string $routeName): void
    {
        $success = match ($routeName) {
            'v1.wallets.deposits.store' => [201, 'DepositCommandResponse'],
            'v1.gateway.deposits.confirm',
            'v1.gateway.deposits.fail' => [200, 'DepositCommandResponse'],
            'v1.wallets.transfers.store' => [200, 'TransferCommandResponse'],
            'v1.wallets.withdrawals.store' => [201, 'WithdrawalCommandResponse'],
            'v1.withdrawals.show',
            'v1.admin.withdrawals.approve',
            'v1.admin.withdrawals.settle',
            'v1.admin.withdrawals.reject' => [200, 'WithdrawalCommandResponse'],
            default => null,
        };

        if ($success === null) {
            return;
        }

        $operation->responses = array_values(array_filter(
            $operation->responses ?? [],
            static fn ($response): bool => ! $response instanceof Response || $response->code !== 0,
        ));

        [$status, $schema] = $success;
        $operation->addResponse(
            Response::make($status)
                ->setDescription($status === 201 ? 'Resource created.' : 'Command completed.')
                ->setContent('application/json', Reference::fromUri("#/components/schemas/{$schema}")),
        );
    }

    private function idempotencyKeyParameter(): Parameter
    {
        $schema = (new StringType)
            ->setMin(1)
            ->setMax(128)
            ->pattern('^[\\x20-\\x7E]+$');

        return Parameter::make('Idempotency-Key', 'header')
            ->required(true)
            ->description('Printable ASCII retry identity. Reusing it with the same request replays the committed result; changing the request returns 409.')
            ->setSchema(Schema::fromType($schema))
            ->example('01JDEMO0000000000000000000');
    }

    private function gatewaySecretParameter(): Parameter
    {
        return Parameter::make('X-Gateway-Secret', 'header')
            ->required(true)
            ->description('Shared demo gateway credential. Invalid credentials return 403.')
            ->setSchema(Schema::fromType(new StringType));
    }

    /** @return list<int> */
    private function errorStatuses(string $routeName): array
    {
        $statuses = [404, 429, 500, 503];

        if (str_contains($routeName, '.store') || str_contains($routeName, '.transactions.')) {
            $statuses[] = 422;
        }

        if (in_array($routeName, self::IDEMPOTENT_ROUTES, true)) {
            $statuses = [...$statuses, 409, 422];
        }

        if (in_array($routeName, self::GATEWAY_ROUTES, true)) {
            $statuses = [...$statuses, 403, 409, 422];
        }

        return array_values(array_unique($statuses));
    }

    private function problemResponse(int $status): Response
    {
        $description = match ($status) {
            403 => 'Gateway credential rejected.',
            404 => 'Referenced resource not found.',
            409 => 'Command conflicts with a committed operation or resource state.',
            422 => 'Request validation or a deterministic business rule failed.',
            429 => 'Request rate limit exceeded; inspect Retry-After.',
            503 => 'Transient database contention; retry according to Retry-After.',
            default => 'Unexpected server error.',
        };

        return Response::make($status)
            ->setDescription($description)
            ->setContent('application/problem+json', Reference::fromUri('#/components/schemas/ProblemDetails'));
    }
}
