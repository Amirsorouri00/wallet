<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RouteContractTest extends TestCase
{
    public function test_all_business_routes_are_versioned_and_named(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn ($route): bool => str_starts_with($route->uri(), 'api/v1/'));

        self::assertCount(11, $routes);

        foreach ($routes as $route) {
            self::assertNotNull($route->getName(), $route->uri().' must have a stable route name.');
            self::assertStringStartsWith('v1.', $route->getName());
        }
    }

    public function test_unversioned_business_route_is_not_exposed(): void
    {
        $this->getJson('/api/deposits/00000000-0000-7000-8000-000000000000')
            ->assertNotFound()
            ->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
    }
}
