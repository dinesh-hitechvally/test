<?php

namespace App\GraphQL;

use GraphQL\Error\ClientAware;
use GraphQL\Error\ProvidesExtensions;
use RuntimeException;

/**
 * An error meant for the user (bad credentials, "not on this watchlist"…),
 * carrying the HTTP status the REST API used to answer with. The frontend's
 * gql() helper turns it back into an axios-style error with that status, so
 * pages handle it exactly as before.
 */
class ApiError extends RuntimeException implements ClientAware, ProvidesExtensions
{
    public function __construct(string $message, private readonly int $status = 422, private readonly array $extra = [])
    {
        parent::__construct($message);
    }

    public function isClientSafe(): bool
    {
        return true;
    }

    public function getExtensions(): array
    {
        return ['status' => $this->status, ...$this->extra];
    }
}
