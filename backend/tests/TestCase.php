<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;

abstract class TestCase extends BaseTestCase
{
    use MakesGraphQLRequests;

    protected function setUp(): void
    {
        parent::setUp();

        // A test never reaches the real nepalstock.com / ShareSansar / MeroLagani: with the failover between
        // them, an unfaked request would quietly succeed against the live site and hide what the test checks.
        Http::preventStrayRequests();
    }

    /** The HTTP-style status a GraphQL error carries (extensions.status), null when the operation succeeded. */
    protected function graphQLStatus(TestResponse $response): ?int
    {
        return $response->json('errors.0.extensions.status');
    }
}
