<?php

namespace App\Services\DataSources;

use GuzzleHttp\Exception\ConnectException;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * Thrown instead of making a request to a website that is currently blocking us (see SourceBlockRegistry). It is
 * a "skipped", not a failure: nothing was sent, so there is nothing new to alert about.
 */
class SourceBlockedException extends ConnectException
{
    public function __construct(public readonly string $website, string $message, RequestInterface $request)
    {
        parent::__construct($message, $request);
    }

    /** The SourceBlockedException behind any exception (Laravel wraps it in a ConnectionException), or null. */
    public static function in(Throwable $e): ?self
    {
        for (; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof self) {
                return $e;
            }
        }

        return null;
    }
}
