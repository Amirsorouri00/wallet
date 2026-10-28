<?php

use App\Http\Errors\ProblemDetailsFactory;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\LogWalletRequest;
use App\Http\Middleware\RejectMalformedJson;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [AssignRequestId::class, LogWalletRequest::class, RejectMalformedJson::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return app(ProblemDetailsFactory::class)->fromException($exception, $request);
        });
    })->create();
