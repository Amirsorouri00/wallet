<?php

namespace Tests\Feature\Api;

use App\Http\Errors\ApiErrorCode;
use App\Http\Errors\ProblemDetailsFactory;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PDOException;
use RuntimeException;
use Tests\TestCase;

class ErrorContractTest extends TestCase
{
    public function test_every_public_error_code_has_one_stable_problem_definition(): void
    {
        $factory = app(ProblemDetailsFactory::class);

        foreach (ApiErrorCode::cases() as $code) {
            $request = Request::create('/api/v1/contract-test');
            $request->attributes->set('request_id', 'error-matrix-test');

            $response = $factory->response($code, $request);
            $body = $response->getData(true);

            self::assertSame($code->status(), $response->getStatusCode(), $code->value);
            self::assertSame('application/problem+json', $response->headers->get('Content-Type'), $code->value);
            self::assertSame($code->value, $body['code'], $code->value);
            self::assertSame($code->title(), $body['title'], $code->value);
            self::assertSame($code->detail(), $body['detail'], $code->value);
            self::assertSame('urn:request:error-matrix-test', $body['instance'], $code->value);
            self::assertStringEndsWith('/'.$code->slug(), $body['type'], $code->value);
        }
    }

    public function test_request_ids_are_propagated_or_replaced_on_success_and_error_responses(): void
    {
        $this->withHeader('X-Request-Id', 'review-run-123')
            ->getJson('/api/health')
            ->assertHeader('X-Request-Id', 'review-run-123');

        $replacement = $this->withHeader('X-Request-Id', 'bad request id')
            ->getJson('/api/health')
            ->assertOk()
            ->headers->get('X-Request-Id');

        self::assertNotSame('bad request id', $replacement);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $replacement);

        $error = $this->postJson('/api/v1/wallets/not-a-uuid/deposits', []);
        $error->assertNotFound()->assertHeader('X-Request-Id');
        self::assertSame(
            'urn:request:'.$error->headers->get('X-Request-Id'),
            $error->json('instance'),
        );
    }

    public function test_malformed_json_and_real_throttle_exceptions_use_problem_details(): void
    {
        Route::middleware('api')->post('/api/v1/contract/malformed', static function (Request $request) {
            return response()->json($request->json()->all());
        });
        Route::middleware('api')->get('/api/v1/contract/throttled', static function (): never {
            throw new ThrottleRequestsException;
        });

        $this->call(
            'POST',
            '/api/v1/contract/malformed',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: '{"broken":',
        )->assertBadRequest()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'MALFORMED_REQUEST');

        $this->getJson('/api/v1/contract/throttled')
            ->assertTooManyRequests()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertHeader('Retry-After', '1')
            ->assertJsonPath('code', 'RATE_LIMIT_EXCEEDED')
            ->assertJsonPath('retryable', true);
    }

    public function test_retryable_postgresql_failures_are_sanitized_after_retry_exhaustion(): void
    {
        foreach (['40P01', '40001', '55P03', '57014'] as $sqlState) {
            $previous = new PDOException('password=secret raw database failure');
            $previous->errorInfo = [$sqlState, 0, 'private database detail'];
            $exception = new QueryException('pgsql', 'select * from private_table', [], $previous);
            $request = Request::create('/api/v1/contract-test');
            $request->attributes->set('request_id', 'database-busy-test');

            $response = app(ProblemDetailsFactory::class)->fromException($exception, $request);
            $encoded = $response->getContent();

            self::assertSame(503, $response->getStatusCode(), $sqlState);
            self::assertSame('1', $response->headers->get('Retry-After'), $sqlState);
            self::assertSame('DATABASE_BUSY', $response->getData(true)['code'], $sqlState);
            self::assertTrue($response->getData(true)['retryable'], $sqlState);
            self::assertStringNotContainsString('secret', $encoded, $sqlState);
            self::assertStringNotContainsString('private_table', $encoded, $sqlState);
        }
    }

    public function test_unknown_exceptions_are_logged_but_publicly_sanitized(): void
    {
        Log::spy();
        Route::middleware('api')->get('/api/v1/contract/unexpected', static function (): never {
            throw new RuntimeException('private token and stack detail');
        });

        $response = $this->getJson('/api/v1/contract/unexpected');

        $response->assertInternalServerError()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'INTERNAL_ERROR');
        self::assertStringNotContainsString('private token', $response->getContent());
        self::assertStringNotContainsString('stack', $response->getContent());
        Log::shouldHaveReceived('error')->atLeast()->once();
    }
}
