<?php

namespace App\Http\Responses;

use App\Data\Wallet\CommandResult;
use App\Domain\Wallet\CommandOutcome;
use App\Http\Errors\ProblemDetailsFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class CommandResultResponse
{
    public function __construct(private ProblemDetailsFactory $problems) {}

    public function make(CommandResult $result, Request $request): JsonResponse
    {
        if ($result->outcome() === CommandOutcome::REJECTED) {
            return $this->problems->forCommandError($result->requiredError(), $request);
        }

        return response()->json($result->body(), self::status($result));
    }

    public static function status(CommandResult $result): int
    {
        return match ($result->outcome()) {
            CommandOutcome::CREATED => 201,
            CommandOutcome::SUCCEEDED => 200,
            CommandOutcome::REJECTED => 422,
        };
    }
}
