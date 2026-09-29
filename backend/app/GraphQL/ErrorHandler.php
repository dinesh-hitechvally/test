<?php

namespace App\GraphQL;

use Closure;
use GraphQL\Error\Error;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Nuwave\Lighthouse\Execution\ErrorHandler as LighthouseErrorHandler;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Gives the exceptions the REST API used to turn into 401/404/422 responses
 * the same meaning over GraphQL: a user-facing message plus
 * extensions.status (and extensions.validation for field errors), which the
 * frontend maps back onto the old axios error shape.
 */
class ErrorHandler implements LighthouseErrorHandler
{
    public function __invoke(?Error $error, Closure $next): ?array
    {
        $previous = $error?->getPrevious();

        $mapped = match (true) {
            $previous instanceof ValidationException => new ApiError($previous->getMessage(), 422, ['validation' => $previous->errors()]),
            $previous instanceof ModelNotFoundException => new ApiError('No query results for model ['.$previous->getModel().'].', 404),
            $previous instanceof AuthenticationException => new ApiError('Unauthenticated.', 401),
            $previous instanceof HttpExceptionInterface => new ApiError($previous->getMessage() ?: 'Error', $previous->getStatusCode()),
            default => null,
        };

        if ($mapped !== null) {
            $error = new Error($mapped->getMessage(), $error->getNodes(), $error->getSource(), $error->getPositions(), $error->getPath(), $mapped);
        }

        return $next($error);
    }
}
