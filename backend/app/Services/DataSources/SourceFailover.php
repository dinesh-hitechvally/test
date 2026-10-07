<?php

namespace App\Services\DataSources;

use App\Events\ScrapeFinished;
use Closure;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Tries data sources in order and uses the first that works. When one fails (blocked, down, changed layout) the
 * failure is logged — to the application log and to the scrape log — together with the source it switches to, so
 * you can see why a different website supplied the data. Only when every source fails does the job fail.
 *
 * Used for the jobs that depend on nepalstock.com, which is sometimes blocked by a network filter or down: the
 * fallbacks are ShareSansar and MeroLagani, which publish the same market data.
 */
class SourceFailover
{
    /**
     * @param  array<string, Closure>  $sources  source name => closure that fetches and returns the result (throws on failure)
     * @return array{0: mixed, 1: string, 2: array<string, string>} the result, the source that supplied it, and
     *                                                              the sources that failed first (name => reason)
     */
    public function run(string $job, array $sources): array
    {
        $names = array_keys($sources);
        $failures = [];
        $skipped = [];

        foreach ($sources as $name => $attempt) {
            try {
                return [$attempt(), $name, $failures];
            } catch (Throwable $e) {
                $next = $names[array_search($name, $names, true) + 1] ?? null;
                $reason = $this->reason($e);
                $failures[$name] = $reason;

                // A website that is already known to block us was not even asked: move on quietly. The block itself
                // was logged once when it started (SourceBlockRegistry), not on every run.
                if (($blocked = SourceBlockedException::in($e)) !== null) {
                    $skipped[$name] = $blocked;
                    Log::info("{$job}: {$name} skipped — ".($next ? "using {$next}" : 'no source left'), ['reason' => $reason]);

                    continue;
                }

                Log::warning("{$job}: {$name} failed".($next ? ", switching to {$next}" : ', no source left'), ['error' => $reason]);

                ScrapeFinished::dispatch(
                    source: $name,
                    succeeded: false,
                    recordsProcessed: 0,
                    message: $next ? "{$reason} — switching to {$next}" : $reason,
                );
            }
        }

        // Every source refused to be asked (all blocked): the job is skipped, not failed — no alert for that.
        $previous = count($skipped) === count($failures) ? reset($skipped) : null;

        throw new RuntimeException("Every source failed for {$job} — ".implode('; ', array_map(fn ($n, $r) => "{$n}: {$r}", array_keys($failures), $failures)), 0, $previous ?: null);
    }

    /** "<h1>Web Page Blocked</h1>…" as a short readable line, whatever the source answered. */
    private function reason(Throwable $e): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('/<(script|style)\b.*?<\/\1>/is', '', $e->getMessage()))));

        return mb_strlen($text) > 300 ? mb_substr($text, 0, 300).'…' : $text;
    }

    /** "nepalstock.com failed (reason)" lines for a success message, empty when nothing failed. */
    public static function note(array $failures): string
    {
        if ($failures === []) {
            return '';
        }

        return ' ['.implode('; ', array_map(fn ($n, $r) => "{$n} failed: {$r}", array_keys($failures), $failures)).']';
    }
}
