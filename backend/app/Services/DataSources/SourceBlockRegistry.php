<?php

namespace App\Services\DataSources;

use App\Events\ScrapeFinished;
use App\Models\SourceBlock;
use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Create;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Remembers which websites are blocking us (the `source_blocks` table) and stops sending them requests.
 *
 * It sits in the HTTP client (AppServiceProvider registers httpMiddleware()), so EVERY request to a tracked website
 * is covered — the stock list, prices, index, dividends, token check — not just the ones a failover knows about:
 *
 *   - a refusal (HTTP 403 / 429 / 451, a web filter's "Web Page Blocked" page, an interrupted certificate) blocks
 *     that website straight away; an outage (5xx, timeouts, connection errors) blocks it after
 *     `transient_failures` in a row;
 *   - while blocked, requests to THAT website are skipped without touching the network (SourceBlockedException);
 *     the other websites are unaffected;
 *   - after `cooldown_minutes` the next request is the retry: success clears the block, a failure blocks again;
 *   - a probe (the check/sources cron) goes through a block to find out whether it has lifted.
 *
 * The block is recorded once (application log + scrape log), not on every skipped request.
 */
class SourceBlockRegistry
{
    /** Handler context errno values that mean "something is intercepting the TLS connection" (60 = unknown issuer, as a web filter causes). */
    private const TLS_ERRORS = [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91];

    private const BLOCK_STATUSES = [403, 407, 429, 451];

    /** A web filter or WAF can answer 200 with a block page, so the start of every body is checked too. */
    private const BLOCK_PAGE = '/Web Page Blocked|Access Denied|has been blocked|Request blocked|You have been blocked/i';

    /** @var array<string, ?SourceBlock> website => row, for this process */
    private array $memo = [];

    /** @return list<string> */
    public function websites(): array
    {
        return config('services.source_block.websites', []);
    }

    /** "www.sharesansar.com" => "sharesansar.com"; null for a website that is not tracked. */
    public function websiteFor(string $host): ?string
    {
        $host = strtolower($host);

        foreach ($this->websites() as $website) {
            if ($host === $website || str_ends_with($host, '.'.$website)) {
                return $website;
            }
        }

        return null;
    }

    /** The row while the website is paused, else null. Never throws (no table yet, no database) — then nothing is blocked. */
    public function paused(string $website): ?SourceBlock
    {
        try {
            $row = $this->memo[$website] ??= SourceBlock::where('website', $website)->first();

            if ($row === null || ! $row->isPaused()) {
                // A pause that has just lapsed is re-read next time, not remembered as blocked.
                unset($this->memo[$website]);

                return null;
            }

            return $row;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The Guzzle middleware: skips a blocked website and records how each request to a tracked one went.
     *
     * @return Closure(callable): Closure
     */
    public function httpMiddleware(): Closure
    {
        return function (callable $handler): Closure {
            return function (RequestInterface $request, array $options) use ($handler) {
                $website = $this->websiteFor($request->getUri()->getHost());

                if ($website === null) {
                    return $handler($request, $options);
                }

                // A probe asks "is it still blocked?", so it is the one request that goes through a block.
                if (empty($options['source_probe']) && ($row = $this->paused($website)) !== null) {
                    return Create::rejectionFor(new SourceBlockedException($website, $this->skipMessage($row), $request));
                }

                try {
                    $promise = $handler($request, $options);
                } catch (Throwable $e) {
                    // A handler that throws at once, instead of returning a rejected promise.
                    $this->recordTransportFailure($website, $e);

                    throw $e;
                }

                return $promise->then(
                    function (ResponseInterface $response) use ($website) {
                        $this->recordResponse($website, $response);

                        return $response;
                    },
                    function ($reason) use ($website) {
                        if ($reason instanceof Throwable && ! ($reason instanceof SourceBlockedException)) {
                            $this->recordTransportFailure($website, $reason);
                        }

                        return Create::rejectionFor($reason);
                    }
                );
            };
        };
    }

    public function recordResponse(string $website, ResponseInterface $response): void
    {
        $status = $response->getStatusCode();
        $page = $this->start($response);

        if (in_array($status, self::BLOCK_STATUSES, true) || preg_match(self::BLOCK_PAGE, $page)) {
            $this->block($website, "HTTP {$status}: ".($this->readable($page) ?: $response->getReasonPhrase()), $status);
        } elseif ($status >= 500) {
            $this->failure($website, "HTTP {$status}", $status);
        } elseif ($status < 400) {
            $this->success($website);
        }
        // Any other 4xx (404, 422 ...) means the website answered normally — it is not blocking us.
    }

    public function recordTransportFailure(string $website, Throwable $e): void
    {
        $message = $this->readable($e->getMessage());
        $errno = $this->curlErrno($e);

        if (in_array($errno, self::TLS_ERRORS, true)) {
            $this->block($website, $message, null);
        } else {
            $this->failure($website, $message, null);
        }
    }

    /** A request worked: forget earlier failures and lift a block. */
    public function success(string $website): void
    {
        $row = $this->row($website);

        if ($row === null || (! $row->is_blocked && $row->consecutive_failures === 0)) {
            return; // nothing to clear: not written on every request
        }

        $wasBlocked = $row->is_blocked;
        $row->update(['is_blocked' => false, 'consecutive_failures' => 0, 'blocked_until' => null, 'last_success_at' => now()]);
        $this->memo[$website] = $row;

        if ($wasBlocked) {
            Log::info("{$website} is reachable again — requests resumed.");
            ScrapeFinished::dispatch(source: $website, succeeded: true, recordsProcessed: 0, message: "{$website} is reachable again — requests resumed.");
        }
    }

    /** One failure that is not (yet) a refusal; enough in a row block the website. */
    public function failure(string $website, string $reason, ?int $status): void
    {
        $row = $this->row($website, create: true);
        $failures = $row->consecutive_failures + 1;

        if ($failures >= (int) config('services.source_block.transient_failures', 3)) {
            $row->consecutive_failures = $failures;
            $this->block($website, "{$failures} failures in a row — last: {$reason}", $status);

            return;
        }

        $row->update(['consecutive_failures' => $failures, 'reason' => $reason, 'http_status' => $status, 'last_failure_at' => now()]);
        $this->memo[$website] = $row;
    }

    /** The website is refusing us: pause every request to it. */
    public function block(string $website, string $reason, ?int $status): void
    {
        $row = $this->row($website, create: true);
        $until = now()->addMinutes((int) config('services.source_block.cooldown_minutes', 30));
        $newBlock = ! $row->is_blocked || ! $row->isPaused();

        $row->fill([
            'is_blocked' => true,
            'reason' => mb_substr($reason, 0, 500),
            'http_status' => $status,
            'consecutive_failures' => max($row->consecutive_failures, 1),
            'blocked_until' => $until,
            'last_failure_at' => now(),
        ]);

        if ($newBlock) {
            $row->blocked_at = now();
            $row->times_blocked++;
        }

        $row->save();
        $this->memo[$website] = $row;

        if ($newBlock) {
            $message = "{$website} is blocking us ({$row->reason}) — every request to it is paused until {$until->format('H:i')}; other websites carry on.";
            Log::warning($message);
            ScrapeFinished::dispatch(source: $website, succeeded: false, recordsProcessed: 0, message: $message);
        }
    }

    /** The state of every tracked website, for the check/sources cron and anything that wants to show it. @return list<SourceBlock> */
    public function status(): array
    {
        $rows = SourceBlock::whereIn('website', $this->websites())->get()->keyBy('website');

        return array_map(fn (string $site) => $rows->get($site) ?? new SourceBlock(['website' => $site, 'is_blocked' => false, 'consecutive_failures' => 0, 'times_blocked' => 0]), $this->websites());
    }

    private function row(string $website, bool $create = false): ?SourceBlock
    {
        try {
            $row = $this->memo[$website] ??= SourceBlock::where('website', $website)->first();

            if ($row === null && $create) {
                $row = $this->memo[$website] = SourceBlock::create(['website' => $website]);
            }

            return $row;
        } catch (Throwable) {
            return null;
        }
    }

    /** curl's error number: from the handler context when the exception carries one, else from "cURL error 60: ..." in its message. */
    private function curlErrno(Throwable $e): ?int
    {
        if (($e instanceof RequestException || $e instanceof ConnectException) && method_exists($e, 'getHandlerContext')) {
            $errno = $e->getHandlerContext()['errno'] ?? null;

            if ($errno !== null) {
                return (int) $errno;
            }
        }

        return preg_match('/cURL error (\d+)/', $e->getMessage(), $m) ? (int) $m[1] : null;
    }

    private function skipMessage(SourceBlock $row): string
    {
        return "{$row->website} is blocking us ({$row->reason}) — its requests are paused until {$row->blocked_until->format('H:i')}, then the next one retries.";
    }

    /** The first bytes of a response, without consuming it for the caller. */
    private function start(ResponseInterface $response): string
    {
        $body = $response->getBody();

        if (! $body->isSeekable()) {
            return '';
        }

        $position = $body->tell();
        $body->rewind();
        $start = $body->read(4000);
        $body->seek($position);

        return $start;
    }

    private function readable(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('/<(script|style)\b.*?<\/\1>/is', '', $text))));

        return mb_strlen($text) > 250 ? mb_substr($text, 0, 250).'…' : $text;
    }
}
