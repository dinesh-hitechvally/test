<?php

namespace App\Http\Controllers;

use App\Models\Stock;
use App\Services\CronAlertService;
use App\Services\MarketData\NepalStockCorporateActionsService;
use App\Services\MarketData\NepalStockSecurityResolver;
use App\Services\MarketData\SharesansarHistoryService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * URL-triggered replacement for the Laravel scheduler (routes/console.php's
 * old Schedule:: block) — each method runs exactly the artisan command the
 * scheduler used to run, so an external pinger (cron-job.org, UptimeRobot,
 * a plain crontab `curl` line, anything that can hit a URL on a timer) can
 * drive the whole pipeline without needing real cron/SSH access on the host.
 * Routes live in routes/web.php, gated by the cron.secret middleware — see
 * VerifyCronSecret. Plain text output, not JSON: this is meant to be read
 * as a log line by whatever's calling it, not consumed by the SPA.
 *
 * Intended timing (NPT = Asia/Kathmandu, UTC+5:45 — see each method; the
 * live, ready-to-paste version with both NPT and UTC cron expressions is
 * also served at GET /api/schedule, ScheduleController::JOBS is the single
 * source of truth if these two ever drift):
 *   stocks:sync-list                06:00 NPT, daily
 *   market:sync                    15:30 NPT, Mon-Fri
 *   market:sync-index              15:32 NPT, Mon-Fri
 *   market:recalculate             15:40 NPT, Mon-Fri
 *   ml:train-predictor             03:30 NPT, Monday
 *   signals:backtest-accuracy      04:00 NPT, Monday
 *
 * fetch-histories/fetch-history/sync-sectors/sync-dividends are on-demand
 * (no fixed schedule, safe to ping repeatedly). There's no URL-triggered
 * queue-worker path anymore — it was fully redundant with fetch-histories,
 * which does the same job directly instead of via a queue.
 *
 * A failing command (non-zero exit code) or a failed on-demand fetch also
 * goes through CronAlertService — always logged, and additionally posted
 * to Slack / emailed if SLACK_WEBHOOK_URL / CRON_ALERT_EMAIL are set in
 * .env (both optional; unset means log-only, not an error).
 */
class CronController extends Controller
{
    public function __construct(private readonly CronAlertService $alerts) {}

    /**
     * 06:00 NPT, daily — cron_utc: 15 0 * * *. Ahead of market-sync (which
     * only creates a stock as a byproduct of it trading that day) so any
     * brand-new or non-trading listing already has a row before the market
     * opens.
     */
    public function syncStockList()
    {
        return $this->run('stocks:sync-list', 'stocks-sync-list.log');
    }

    /** 15:30 NPT, Mon-Fri — cron_utc: 45 9 * * 1-5 */
    public function marketSyncStock()
    {
        return $this->run('market:sync', 'market-sync.log');
    }

    /** 15:40 NPT, Mon-Fri (after market-sync) — cron_utc: 55 9 * * 1-5 */
    public function marketRecalculate()
    {
        return $this->run('market:recalculate', 'market-recalculate.log');
    }

    /** 15:32 NPT, Mon-Fri — cron_utc: 47 9 * * 1-5 */
    public function marketSyncIndex()
    {
        return $this->run('market:sync-index', 'market-sync-index.log');
    }

    /**
     * No fixed timing — this is the on-demand, one-stock counterpart to
     * fetch-histories (which handles the whole market in small batches).
     * Same ShareSansar full-history fetch as the "Fetch Full History"
     * button on that stock's detail page, just callable by URL instead of
     * needing to log into the SPA. Re-running it re-fetches (safe — it's an
     * upsert), it's not limited to stocks that never had one.
     */
    public function fetchHistory(string $symbol, SharesansarHistoryService $history)
    {
        set_time_limit(0);

        $stock = Stock::where('symbol', strtoupper($symbol))->first();

        if (! $stock) {
            return response("No stock found for symbol [{$symbol}].", 404)->header('Content-Type', 'text/plain');
        }

        try {
            $result = $history->fetchFullHistory($stock);

            return response(
                "\$ fetch-history {$stock->symbol}\n".
                "{$result['rows_imported']} rows imported ({$result['oldest_date']} to {$result['newest_date']})."
            )->header('Content-Type', 'text/plain');
        } catch (Throwable $e) {
            $this->alerts->notifyFailure("fetch-history/{$stock->symbol}", $e->getMessage());

            return response("Fetch failed for {$stock->symbol}: {$e->getMessage()}", 502)
                ->header('Content-Type', 'text/plain');
        }
    }

    /**
     * No fixed timing, safe to ping often (e.g. every 15 min) — fetches full
     * history directly for stocks missing it, a handful at a time (?limit=,
     * default 5). Deliberately batched small — a web request/reverse-proxy
     * timeout would otherwise kill a run partway through many stocks, each
     * needing several paginated ShareSansar requests. That's harmless here:
     * fetchFullHistory() sets history_fetched_at as each stock finishes, so
     * an interrupted run just picks up where it left off next time it's
     * pinged. One stock's failure doesn't stop the rest in the batch.
     */
    public function fetchHistories(Request $request, SharesansarHistoryService $history)
    {
        set_time_limit(0);

        $limit = max(1, (int) $request->query('limit', 1));
        $stocks = Stock::whereNull('history_fetched_at')->orderBy('id')->limit($limit)->get();

        if ($stocks->isEmpty()) {
            return response("No stocks are missing full history.\n")->header('Content-Type', 'text/plain');
        }

        $lines = [];

        foreach ($stocks as $stock) {
            try {
                $result = $history->fetchFullHistory($stock);
                $lines[] = "{$stock->symbol}: {$result['rows_imported']} rows imported ({$result['oldest_date']} to {$result['newest_date']}).";
            } catch (Throwable $e) {
                $lines[] = "{$stock->symbol}: failed — {$e->getMessage()}";
            }
        }

        $remaining = Stock::whereNull('history_fetched_at')->count();
        $lines[] = "{$remaining} stock(s) still missing history — re-ping this URL to continue.";

        return response(implode("\n", $lines))->header('Content-Type', 'text/plain');
    }

    /**
     * No fixed timing, safe to ping often — same batched, timeout-proof
     * shape as fetchHistories() (?limit=, default 5), for the same reason:
     * a request covering hundreds of stocks would risk a web server timeout,
     * but each stock's sector is saved as soon as it's fetched, so an
     * interrupted run just continues on the next ping.
     */
    public function syncSectors(Request $request, NepalStockSecurityResolver $resolver)
    {
        set_time_limit(0);

        $limit = max(1, (int) $request->query('limit', 5));
        $stocks = Stock::whereNull('sector')->orderBy('id')->limit($limit)->get();

        if ($stocks->isEmpty()) {
            return response("No stocks are missing a sector.\n")->header('Content-Type', 'text/plain');
        }

        $lines = [];

        foreach ($stocks as $stock) {
            try {
                $sector = $resolver->fetchSector($stock);

                if ($sector !== null) {
                    $stock->update(['sector' => $sector]);
                    $stock->clearScrapeError();
                    $lines[] = "{$stock->symbol}: {$sector}";
                } else {
                    $lines[] = "{$stock->symbol}: no sector returned";
                }
            } catch (Throwable $e) {
                $stock->flagScrapeError('sector', $e->getMessage());
                $lines[] = "{$stock->symbol}: failed — {$e->getMessage()}";
            }

            usleep(300_000); // same polite pacing as stocks:backfill-sectors
        }

        $remaining = Stock::whereNull('sector')->count();
        $lines[] = "{$remaining} stock(s) still missing a sector — re-ping this URL to continue.";

        return response(implode("\n", $lines))->header('Content-Type', 'text/plain');
    }

    /**
     * No fixed timing, safe to ping often — same batched, timeout-proof
     * shape as fetchHistories()/syncSectors() (?limit=, default 5).
     * Dividend/bonus data (nepalstock.com's only source for it) previously
     * had no cron path at all, only the manual
     * stocks:backfill-corporate-actions command or the per-stock "Refresh
     * Dividend/Bonus Data" button. Targets stocks with zero dividend rows
     * recorded yet; run stocks:backfill-corporate-actions --all from the
     * CLI instead if you need to refresh a stock that already has rows
     * (e.g. a newly-declared dividend).
     */
    public function syncDividends(Request $request, NepalStockCorporateActionsService $dividends)
    {
        set_time_limit(0);

        $limit = max(1, (int) $request->query('limit', 5));
        $stocks = Stock::whereDoesntHave('dividends')->orderBy('id')->limit($limit)->get();

        if ($stocks->isEmpty()) {
            return response("No stocks are missing dividend data.\n")->header('Content-Type', 'text/plain');
        }

        $lines = [];

        foreach ($stocks as $stock) {
            try {
                $result = $dividends->fetchDividends($stock);
                $stock->clearScrapeError();
                $lines[] = "{$stock->symbol}: {$result['dividends']} dividend row(s) imported.";
            } catch (Throwable $e) {
                $stock->flagScrapeError('dividend', $e->getMessage());
                $lines[] = "{$stock->symbol}: failed — {$e->getMessage()}";
            }

            usleep(500_000); // same polite pacing as stocks:backfill-corporate-actions
        }

        $remaining = Stock::whereDoesntHave('dividends')->count();
        $lines[] = "{$remaining} stock(s) still missing dividend data — re-ping this URL to continue.";

        return response(implode("\n", $lines))->header('Content-Type', 'text/plain');
    }

    /** 03:30 NPT, Monday — cron_utc: 45 21 * * 0 (Sunday in UTC) */
    public function trainMl()
    {
        return $this->run('ml:train-predictor', 'ml-train.log');
    }

    /** 04:00 NPT, Monday — cron_utc: 15 22 * * 0 (Sunday in UTC) */
    public function backtestSignals()
    {
        return $this->run('signals:backtest-accuracy', 'signal-accuracy.log');
    }

    private function run(string $signature, string $logFile, array $params = []): Response
    {
        set_time_limit(0);

        $exitCode = Artisan::call($signature, $params);
        $output = Artisan::output();

        file_put_contents(
            storage_path('logs/'.$logFile),
            '['.now()->toDateTimeString().'] '.$output,
            FILE_APPEND
        );

        if ($exitCode !== 0) {
            $this->alerts->notifyFailure($signature, $output ?: "Exited with code {$exitCode}, no output.");
        }

        return response("\$ php artisan {$signature}\n{$output}\n[exit code {$exitCode}]")
            ->header('Content-Type', 'text/plain');
    }
}
