<?php

namespace App\Tasks\DataQuality;

use App\Services\DataSources\SourceBlockRegistry;
use App\Services\DataSources\SourceBlockedException;
use App\Tasks\Task;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Which websites are blocking us right now. Sends each tracked website one light request THROUGH any block — the
 * only request that is — so a block that has lifted is noticed straight away (and requests resume) instead of
 * waiting out the pause, and a block that has not is confirmed. Then lists every website's state.
 *
 * Failing here is not a task failure: a blocked website is a state to report, and the other websites'
 * jobs have already switched over (SourceFailover).
 */
class CheckSourcesTask extends Task
{
    /** A page small enough to be a cheap check, per tracked website. */
    private const PROBES = [
        'nepalstock.com' => 'https://www.nepalstock.com/api/authenticate/prove',
        'sharesansar.com' => 'https://www.sharesansar.com/',
        'merolagani.com' => 'https://merolagani.com/',
    ];

    public function __construct(private readonly SourceBlockRegistry $blocks) {}

    public function handle(): string
    {
        $lines = [];

        foreach ($this->blocks->websites() as $website) {
            $url = self::PROBES[$website] ?? "https://{$website}/";

            try {
                // The block middleware records the outcome (a refusal blocks, a good answer clears the block).
                Http::withOptions(['source_probe' => true])->withHeaders(['User-Agent' => 'Mozilla/5.0'])->timeout(15)->get($url);
            } catch (SourceBlockedException) {
                // Not reachable with a probe: cannot happen, a probe bypasses the pause.
            } catch (Throwable) {
                // Already recorded by the middleware as a failure.
            }
        }

        foreach ($this->blocks->status() as $row) {
            $lines[] = $row->is_blocked
                ? sprintf('%-16s BLOCKED since %s — %s (paused until %s; blocked %d time(s) so far)', $row->website, $row->blocked_at?->format('Y-m-d H:i'), $row->reason, $row->blocked_until?->format('H:i'), $row->times_blocked)
                : sprintf('%-16s ok%s', $row->website, $row->consecutive_failures > 0 ? " ({$row->consecutive_failures} recent failure(s): {$row->reason})" : '');
        }

        return implode("\n", $lines);
    }
}
