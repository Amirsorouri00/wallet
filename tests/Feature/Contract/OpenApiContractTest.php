<?php

namespace Tests\Feature\Contract;

use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

class OpenApiContractTest extends TestCase
{
    use RefreshDatabase;

    private const IDEMPOTENT_ROUTES = [
        'v1.wallets.deposits.store',
        'v1.wallets.transfers.store',
        'v1.wallets.withdrawals.store',
        'v1.admin.withdrawals.approve',
        'v1.admin.withdrawals.settle',
        'v1.admin.withdrawals.reject',
    ];

    private const GATEWAY_ROUTES = [
        'v1.gateway.deposits.confirm',
        'v1.gateway.deposits.fail',
    ];

    public function test_generated_document_covers_the_versioned_api_contract(): void
    {
        $this->withoutExceptionHandling();
        $this->withoutMiddleware(RestrictedDocsAccess::class);

        $document = $this->getJson('/docs/api.json')
            ->assertOk()
            ->assertJsonPath('openapi', '3.1.2')
            ->json();

        self::assertSame('/api/v1', parse_url($document['servers'][0]['url'] ?? '', PHP_URL_PATH));
        self::assertArrayHasKey('ProblemDetails', $document['components']['schemas'] ?? []);

        $documentedOperations = $this->documentedOperations($document);
        $apiRoutes = $this->versionedRoutes();

        self::assertCount($apiRoutes->count(), $documentedOperations);

        foreach ($apiRoutes as $route) {
            $key = strtolower(implode(' ', [$route->methods()[0], $this->documentPath($route)]));
            self::assertArrayHasKey($key, $documentedOperations, "{$route->getName()} is missing from OpenAPI.");
        }

        $operationIds = array_column($documentedOperations, 'operationId');
        self::assertNotContains(null, $operationIds);
        self::assertCount(count($operationIds), array_unique($operationIds), 'OpenAPI operation IDs must be unique.');

        foreach (self::IDEMPOTENT_ROUTES as $routeName) {
            $this->assertRequiredHeader($documentedOperations, $routeName, 'Idempotency-Key');
        }

        foreach (self::GATEWAY_ROUTES as $routeName) {
            $this->assertRequiredHeader($documentedOperations, $routeName, 'X-Gateway-Secret');
        }

        $documentedErrorStatuses = [];

        foreach ($documentedOperations as $operation) {
            foreach (['500', '503'] as $status) {
                $schemaReference = $operation['responses'][$status]['content']['application/problem+json']['schema']['$ref'] ?? null;
                self::assertSame('#/components/schemas/ProblemDetails', $schemaReference);
            }

            foreach ($operation['responses'] ?? [] as $status => $response) {
                if ((int) $status < 400) {
                    continue;
                }

                $documentedErrorStatuses[] = (int) $status;
                $schemaReference = $response['content']['application/problem+json']['schema']['$ref'] ?? null;
                self::assertSame('#/components/schemas/ProblemDetails', $schemaReference);
            }
        }

        foreach ([403, 404, 409, 422, 429, 500, 503] as $status) {
            self::assertContains($status, $documentedErrorStatuses);
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function documentedOperations(array $document): array
    {
        $operations = [];

        foreach ($document['paths'] ?? [] as $path => $pathItem) {
            foreach ($pathItem as $method => $operation) {
                if (! in_array(strtolower($method), ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    continue;
                }

                $operations[strtolower("{$method} {$path}")] = $operation;
            }
        }

        return $operations;
    }

    /**
     * @return Collection<int, Route>
     */
    private function versionedRoutes()
    {
        return collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(static fn (Route $route): bool => str_starts_with($route->uri(), 'api/v1/'))
            ->values();
    }

    private function documentPath(Route $route): string
    {
        return '/'.str($route->uri())->after('api/v1/');
    }

    /**
     * @param  array<string, array<string, mixed>>  $documentedOperations
     */
    private function assertRequiredHeader(array $documentedOperations, string $routeName, string $header): void
    {
        /** @var Route $route */
        $route = RouteFacade::getRoutes()->getByName($routeName);
        self::assertNotNull($route);

        $key = strtolower(implode(' ', [$route->methods()[0], $this->documentPath($route)]));
        $parameters = $documentedOperations[$key]['parameters'] ?? [];
        $parameter = collect($parameters)->first(
            static fn (array $candidate): bool => ($candidate['in'] ?? null) === 'header'
                && strcasecmp((string) ($candidate['name'] ?? ''), $header) === 0,
        );

        self::assertNotNull($parameter, "{$routeName} must document {$header}.");
        self::assertTrue($parameter['required'] ?? false, "{$routeName} must require {$header}.");
    }
}
