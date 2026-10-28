<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $candidate = $request->header('X-Request-Id');
        $requestId = is_string($candidate) && preg_match('/\A[A-Za-z0-9._:-]{1,100}\z/D', $candidate) === 1
            ? $candidate
            : (string) Str::uuid7();

        $request->attributes->set('request_id', $requestId);
        Log::withContext(['request_id' => $requestId]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
