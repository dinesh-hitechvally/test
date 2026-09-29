<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;

abstract class TestCase extends BaseTestCase
{
    use MakesGraphQLRequests;

    /** The HTTP-style status a GraphQL error carries (extensions.status), null when the operation succeeded. */
    protected function graphQLStatus(TestResponse $response): ?int
    {
        return $response->json('errors.0.extensions.status');
    }
}
