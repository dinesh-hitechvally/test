<?php

namespace App\Services\Docs;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use ReflectionClass;
use Throwable;

/**
 * Lists the /cron/* URLs straight from the router (routes/web.php), with each
 * task's own docblock as its description — so the cron docs follow the routes.
 * Only the scheduling hints below are written by hand.
 */
class CronDocsService
{
    /** When to run each task, and what it needs. Keyed by task class basename. */
    private const HINTS = [
        'SyncStockListTask' => ['when' => 'Daily, 06:00', 'note' => 'Run first on a new install — every other task works on this stock list.'],
        'MarketSyncTask' => ['when' => 'Mon–Fri, 15:30 (after the ~15:00 close); optionally also during the session', 'note' => 'Safe to run repeatedly. While the market is open it stores live prices; once closed it stores the final ones.'],
        'MarketSyncIndexTask' => ['when' => 'Mon–Fri, 15:32', 'note' => 'Does nothing on days the market is closed.'],
        'FetchHistoriesTask' => ['when' => 'Once after adding stocks, then only if some are missing history', 'note' => 'Processes every pending stock in one run; ping again to resume if the host cut it short.'],
        'FetchStockHistoryTask' => ['when' => 'On demand', 'note' => 'Replace {symbol} with e.g. NABIL. Also how to retry a stock whose history fetch failed.'],
        'SyncSectorsTask' => ['when' => 'After the stock list changes', 'note' => 'Only touches stocks without a sector.'],
        'SyncDividendsTask' => ['when' => 'Weekly', 'note' => 'Only stocks never fetched; use the stock page button to refresh one.'],
        'SyncFundamentalsTask' => ['when' => 'Weekly', 'note' => 'Refreshes rows older than about 7 days.'],
        'RecalculateMarketTask' => ['when' => 'Mon–Fri, 15:40 (after the price sync)', 'note' => 'The only cron that generates technical indicators, signals and next-close estimates. Add ?all=1 to force every stock after changing indicator or signal rules.'],
        'GenerateAiOpinionsTask' => ['when' => 'Daily, after generate/indicators', 'note' => 'Needs GROQ_API_KEY; without it the run just reports "not configured". Slow: about 2 stocks per minute on the free Groq tier.'],
        'TrainMlTask' => ['when' => 'Daily, 03:30', 'note' => 'Memory heavy (raises the PHP memory limit to 2 GB for the run).'],
        'BacktestSignalsTask' => ['when' => 'Daily, 04:00', 'note' => 'Re-run after any change to indicator or signal logic.'],
        'BacktestNextCloseTask' => ['when' => 'Daily, 04:15', 'note' => null],
        'VerifyNepseTokenTask' => ['when' => 'On demand', 'note' => 'First thing to run when every nepalstock.com fetch starts failing at once.'],
        'ScanDataQualityTask' => ['when' => 'Mondays, 04:30', 'note' => null],
    ];

    /** @return list<array<string, mixed>> */
    public function jobs(): array
    {
        $jobs = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            /** @var LaravelRoute $route */
            $class = $route->defaults['task'] ?? null;

            if (! str_starts_with($route->uri(), 'cron/') || ! $class) {
                continue;
            }

            $uri = Str::after($route->uri(), 'cron/');
            $short = class_basename($class);
            $hint = self::HINTS[$short] ?? ['when' => null, 'note' => null];
            $name = Str::kebab(Str::beforeLast($short, 'Task'));

            $jobs[] = [
                'group' => Str::before($uri, '/'),
                'path' => '/'.$route->uri(),
                'task' => $short,
                'name' => $name,
                'log' => $name.'.log',
                'description' => $this->describe($class),
                'when' => $hint['when'],
                'note' => $hint['note'],
                'params' => $this->params($class, $route),
            ];
        }

        return $jobs;
    }

    /** @return array<string, list<array<string, mixed>>> keyed by fetch / generate / check, in that order */
    public function grouped(): array
    {
        $groups = ['fetch' => [], 'generate' => [], 'check' => []];

        foreach ($this->jobs() as $job) {
            $groups[$job['group']][] = $job;
        }

        return array_filter($groups);
    }

    /** The task's class docblock as plain paragraphs. */
    private function describe(string $class): string
    {
        try {
            $doc = (new ReflectionClass($class))->getDocComment() ?: '';
        } catch (Throwable) {
            return '';
        }

        $lines = array_map(fn ($l) => trim(preg_replace('#^\s*(/\*\*|\*/|\*)\s?#', '', $l)), explode("\n", $doc));

        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }

    /** @return list<array{name: string, where: string, description: string}> */
    private function params(string $class, LaravelRoute $route): array
    {
        $params = [['name' => 'key', 'where' => 'query', 'description' => 'The CRON_SECRET. Required (not checked when APP_ENV=local).']];

        foreach ($route->parameterNames() as $name) {
            $params[] = ['name' => $name, 'where' => 'path', 'description' => 'Stock symbol, e.g. NABIL.'];
        }

        if (str_contains(file_get_contents((new ReflectionClass($class))->getFileName()), "boolean('all')")) {
            $params[] = ['name' => 'all', 'where' => 'query', 'description' => '1 = process every stock, not just the ones that changed.'];
        }

        return $params;
    }
}
