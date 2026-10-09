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
        'SyncStockListTask' => ['when' => 'Daily, 06:00', 'note' => 'Run first on a new install — every other task works on this stock list. It also fills in each stock sector and instrument type from the NEPSE company list (one extra request); promoter / preference shares such as ACLBSLP take the sector of their parent company (ACLBSL). Source: nepalstock.com; if it cannot be reached (blocked, down) the failure is logged and MeroLagani company list is used instead — the result line says which one supplied the data.'],
        'MarketSyncTask' => ['when' => 'Sun–Thu (NEPSE trading days): every 5 minutes 11:00–15:00 for live prices, then once at 15:30 for the final ones', 'note' => 'Safe to run repeatedly. While the market is open it stores live prices; once closed it stores the final ones. Live prices come from nepalstock.com, then ShareSansar, then MeroLagani if the one before cannot be read — each failure is logged (see Scrape logs) and the result line names the source used. Fallback live prices have an estimated turnover (volume x price) and MeroLagani open / high / low are approximate; the 15:30 run replaces them with ShareSansar final prices.'],
        'MarketSyncIndexTask' => ['when' => 'Sun–Thu, during the session (e.g. 14:55) — NOT after the close', 'note' => 'Stores the index only while the market is open, so a run after 15:00 does nothing. Source: nepalstock.com, then ShareSansar market page if nepalstock.com cannot be reached (52-week high / low are not available from ShareSansar and are left empty).'],
        'FetchHistoriesTask' => ['when' => 'Once after adding stocks, then only if some are missing history', 'note' => 'Fetches ONE pending stock per run (about a minute for a long-listed one) and reports how many are still waiting. Ping it again for the next; the stock list is worked through in id order.'],
        'FetchStockDividendsTask' => ['when' => 'On demand', 'note' => 'Replace {symbol} with e.g. NABIL. Fetches that one stock even if it was fetched before, so it refreshes a newly declared dividend or retries a failed stock.'],
        'FetchStockFundamentalsTask' => ['when' => 'On demand', 'note' => 'Replace {symbol} with e.g. NABIL. Refreshes that one stock EPS, P/E and book value now, even if its last fetch is still fresh.'],
        'FetchStockHistoryTask' => ['when' => 'On demand', 'note' => 'Replace {symbol} with e.g. NABIL. Also how to retry a stock whose history fetch failed.'],
        'SyncDividendsTask' => ['when' => 'Every minute or two until it says nothing is left, then only when new stocks appear', 'note' => 'Fetches ONE stock per run (only stocks never fetched) and reports how many are still waiting. A failed stock goes to the back of the queue. Use the stock page button to refresh one stock.'],
        'SyncFundamentalsTask' => ['when' => 'Every minute or two until it says nothing is due; then weekly', 'note' => 'Fetches ONE stock per run (never-fetched first, then the stalest; rows older than 7 days are due) and reports how many are still due. Debentures, preference shares and mutual funds are skipped (no meaningful EPS or P/E). A stock whose page fails is held back for 6 hours, then retried.'],
        'RecalculateMarketTask' => ['when' => 'Sun–Thu, 15:40 (after the final price sync)', 'note' => 'The only cron that generates technical indicators (and the next-close estimates built on them). It makes no signals: run generate/signals right after. Add ?all=1 to force every stock after changing the indicator maths.'],
        'GenerateSignalsTask' => ['when' => 'Sun–Thu, 15:45 (after generate/indicators)', 'note' => 'The only cron that generates buy / sell / hold signals, from the indicators already stored. Each day gets Buy / Sell / Hold percentages worked out from individual conditions, grouped into seven weighted categories (config/signals.php), and the decision follows from those percentages; the breakdown for every day is kept in signal_breakdowns. It never recalculates an indicator. Picks up stocks whose indicators are new or newer than their signals; add ?all=1 to regenerate every stock after changing the signal rules.'],
        'GenerateAiOpinionsTask' => ['when' => 'Every minute after generate/signals, until it says nothing is due', 'note' => 'Needs GROQ_API_KEY; without it the run just reports "not configured". Handles ONE stock per run (the free Groq tier allows about 2 a minute, and doing them all in one request timed out) and reports how many are still pending. If Groq is rate limiting or does not answer within 25 seconds, the run ends at once, the stock stays pending and the next run retries it; nothing is recorded as a failure. A successful line shows how many seconds it took.'],
        'TrainMlTask' => ['when' => 'Daily, 03:30', 'note' => 'Starts the training in a BACKGROUND process and returns at once (it takes several minutes, far longer than a web request may last). Ping again to see whether it is still running or what it produced; it never starts a second run meanwhile, and a model trained in the last 6 hours is reported instead of retrained (add ?force=1 to override). Memory heavy: the run raises the PHP memory limit to 2 GB. If the server forbids background processes, schedule `php artisan ml:train` in cPanel Cron Jobs instead; set ML_PHP_BINARY if the PHP CLI is not detected.'],
        'BacktestSignalsTask' => ['when' => 'Daily, 04:00', 'note' => 'Re-run after any change to indicator or signal logic. Besides each signal type it measures days grouped by confidence (Buy % / Sell % bands, including the bands under the 50% decision line), which shows whether the percentages mean anything and whether 50% is the right threshold. Fundamental and valuation only exist for the latest day, so a backtest covers the other five categories.'],
        'BacktestNextCloseTask' => ['when' => 'Daily, 04:15', 'note' => null],
        'VerifyNepseTokenTask' => ['when' => 'On demand', 'note' => 'First thing to run when every nepalstock.com fetch starts failing at once. If it answers "Web Page Blocked" or a certificate error, the problem is the network (a web filter), not the code; the price, stock-list and index jobs switch to ShareSansar / MeroLagani by themselves.'],
        'CheckSourcesTask' => ['when' => 'Every 15–30 minutes (and on demand)', 'note' => 'Shows which websites are blocking us (nepalstock.com, sharesansar.com, merolagani.com) and why. While one blocks us, every request to it is skipped — the jobs that depend only on it report "Skipped" instead of failing — and the other websites carry on. This is the one request that goes through a block, so the block lifts as soon as the website answers again; otherwise the next request after the pause (SOURCE_BLOCK_COOLDOWN_MINUTES, 30) retries. State is kept in the source_blocks table.'],
        'ScanDataQualityTask' => ['when' => 'Weekly (e.g. Fridays, 04:30)', 'note' => null],
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
