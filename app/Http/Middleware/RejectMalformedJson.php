<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class RejectMalformedJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $content = $request->getContent();

        if ($request->isJson() && trim($content) !== '') {
            try {
                json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new BadRequestHttpException('Malformed JSON request body.', $exception);
            }
        }

        return $next($request);
    }
}
