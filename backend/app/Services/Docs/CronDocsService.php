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
        'SyncStockListTask' => ['when' => 'Daily, 06:00', 'note' => 'Run first on a new install — every other task works on this stock list. It also fills in each stock sector and instrument type from the NEPSE company list (one extra request); promoter / preference shares such as ACLBSLP take the sector of their parent company (ACLBSL).'],
        'MarketSyncTask' => ['when' => 'Mon–Fri, 15:30 (after the ~15:00 close); optionally also during the session', 'note' => 'Safe to run repeatedly. While the market is open it stores live prices; once closed it stores the final ones.'],
        'MarketSyncIndexTask' => ['when' => 'Mon–Fri, 15:32', 'note' => 'Does nothing on days the market is closed.'],
        'FetchHistoriesTask' => ['when' => 'Once after adding stocks, then only if some are missing history', 'note' => 'Fetches ONE pending stock per run (about a minute for a long-listed one) and reports how many are still waiting. Ping it again for the next; the stock list is worked through in id order.'],
        'FetchStockDividendsTask' => ['when' => 'On demand', 'note' => 'Replace {symbol} with e.g. NABIL. Fetches that one stock even if it was fetched before, so it refreshes a newly declared dividend or retries a failed stock.'],
        'FetchStockFundamentalsTask' => ['when' => 'On demand', 'note' => 'Replace {symbol} with e.g. NABIL. Refreshes that one stock EPS, P/E and book value now, even if its last fetch is still fresh.'],
        'FetchStockHistoryTask' => ['when' => 'On demand', 'note' => 'Replace {symbol} with e.g. NABIL. Also how to retry a stock whose history fetch failed.'],
        'SyncDividendsTask' => ['when' => 'Every minute or two until it says nothing is left, then only when new stocks appear', 'note' => 'Fetches ONE stock per run (only stocks never fetched) and reports how many are still waiting. A failed stock goes to the back of the queue. Use the stock page button to refresh one stock.'],
        'SyncFundamentalsTask' => ['when' => 'Every minute or two until it says nothing is due; then weekly', 'note' => 'Fetches ONE stock per run (never-fetched first, then the stalest; rows older than 7 days are due) and reports how many are still due. Debentures, preference shares and mutual funds are skipped (no meaningful EPS or P/E). A stock whose page fails is held back for 6 hours, then retried.'],
        'RecalculateMarketTask' => ['when' => 'Mon–Fri, 15:40 (after the price sync)', 'note' => 'The only cron that generates technical indicators (and the next-close estimates built on them). It makes no signals: run generate/signals right after. Add ?all=1 to force every stock after changing the indicator maths.'],
        'GenerateSignalsTask' => ['when' => 'Mon–Fri, 15:45 (after generate/indicators)', 'note' => 'The only cron that generates buy / sell / hold signals, from the indicators already stored. It never recalculates an indicator. Picks up stocks whose indicators are new or newer than their signals; add ?all=1 to regenerate every stock after changing the signal rules.'],
        'GenerateAiOpinionsTask' => ['when' => 'Daily, after generate/signals', 'note' => 'Needs GROQ_API_KEY; without it the run just reports "not configured". Slow: about 2 stocks per minute on the free Groq tier.'],
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

    /** @return array<string, list<array<string, mixed>>> keyed by fetch / generate / backtest / check, in that order */
    public function grouped(): array
    {
        $groups = ['fetch' => [], 'generate' => [], 'backtest' => [], 'check' => []];

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
