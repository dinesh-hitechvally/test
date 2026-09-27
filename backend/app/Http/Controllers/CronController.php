<?php

namespace App\Http\Controllers;

use App\Contracts\PriceHistorySource;
use App\Events\CronTaskFailed;
use App\Models\Stock;
use App\Services\Cron\Tasks\BacktestNextCloseTask;
use App\Services\Cron\Tasks\BacktestSignalsTask;
use App\Services\Cron\Tasks\CronTask;
use App\Services\Cron\Tasks\CronTaskRunner;
use App\Services\Cron\Tasks\FetchHistoriesTask;
use App\Services\Cron\Tasks\GenerateAiOpinionsTask;
use App\Services\Cron\Tasks\MarketSyncIndexTask;
use App\Services\Cron\Tasks\MarketSyncTask;
use App\Services\Cron\Tasks\RecalculateMarketTask;
use App\Services\Cron\Tasks\SyncDividendsTask;
use App\Services\Cron\Tasks\SyncFundamentalsTask;
use App\Services\Cron\Tasks\SyncSectorsTask;
use App\Services\Cron\Tasks\SyncStockListTask;
use App\Services\Cron\Tasks\TrainMlTask;
use App\Services\Cron\Tasks\VerifyNepseTokenTask;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * URL-triggered replacement for the Laravel scheduler — an external pinger
 * (cron-job.org, UptimeRobot, a plain crontab `curl` line, anything that
 * can hit a URL on a timer) drives the whole pipeline without needing real
 * cron/SSH access on the host. Routes live in routes/web.php, gated by the
 * cron.secret middleware — see VerifyCronSecret. Plain text output, not
 * JSON: this is meant to be read as a log line by whatever's calling it,
 * not consumed by the SPA.
 *
 * This class is only the HTTP edge — nothing here goes through artisan.
 * Every URL runs one CronTask (App\Services\Cron\Tasks) through
 * CronTaskRunner, which calls the underlying services directly. The
 * per-stock ones (PerStockTask) work through every pending stock in a
 * single run — no batches, no ?limit=.
 *
 * The URLs only START the pipeline — what follows is event-driven. Any
 * price update (market sync, history fetch, CSV import) fires
 * StockPricesUpdated, whose listener recalculates exactly those stocks'
 * indicators/signals/next-close in the same request. Listeners are
 * synchronous (no queue worker on this hosting), so market-sync's request
 * now includes the recalculation.
 *
 * Intended timing (NPT = Asia/Kathmandu, UTC+5:45 — see each method; the
 * live, ready-to-paste version with both NPT and UTC cron expressions is
 * also served at GET /api/schedule, ScheduleController::JOBS is the single
 * source of truth if these two ever drift):
 *   stocks:sync-list                06:00 NPT, daily
 *   market:sync                    15:30 NPT, Mon-Fri  (+ recalculation, via event)
 *   market:sync-index              15:32 NPT, Mon-Fri
 *   ml:train-predictor             03:30 NPT, Monday
 *   signals:backtest-accuracy      04:00 NPT, Monday
 *   signals:backtest-next-close    04:15 NPT, Monday
 *
 * market-recalculate/fetch-histories/fetch-history/sync-sectors/
 * sync-dividends/ai-opinions/fundamentals/verify-token are on-demand (no
 * fixed schedule). The per-stock ones can run a long time on a first run
 * (e.g. ai-opinions ≈ pending stocks ÷ 2 minutes); each stock is saved as
 * it finishes, so if the host cuts a request short, the next ping resumes.
 *
 * A failed task or a failed on-demand fetch fires CronTaskFailed; its
 * listener (AlertCronFailure → CronAlertService) always logs it, and also
 * posts to Slack / emails if SLACK_WEBHOOK_URL / CRON_ALERT_EMAIL are set
 * in .env (both optional; unset means log-only, not an error).
 */
class CronController extends Controller
{
    public function __construct(
        private readonly CronTaskRunner $tasks,
    ) {}

    /**
     * 06:00 NPT, daily — cron_utc: 15 0 * * *. Ahead of market-sync (which
     * only creates a stock as a byproduct of it trading that day) so any
     * brand-new or non-trading listing already has a row before the market
     * opens.
     */
    public function syncStockList(SyncStockListTask $task): Response
    {
        return $this->task($task);
    }

    /** 15:30 NPT, Mon-Fri — cron_utc: 45 9 * * 1-5 */
    public function marketSyncStock(MarketSyncTask $task): Response
    {
        return $this->task($task);
    }

    /**
     * On-demand only — the daily recalculation now follows market-sync
     * automatically (StockPricesUpdated). Useful to re-run today's stocks
     * by hand, or with ?all=1 to re-score every stock after changing
     * indicator or signal rules (takes a few minutes).
     */
    public function marketRecalculate(Request $request, RecalculateMarketTask $task): Response
    {
        return $this->task($task->forAll($request->boolean('all')));
    }

    /** 15:32 NPT, Mon-Fri — cron_utc: 47 9 * * 1-5 */
    public function marketSyncIndex(MarketSyncIndexTask $task): Response
    {
        return $this->task($task);
    }

    /** 03:30 NPT, Monday — cron_utc: 45 21 * * 0 (Sunday in UTC) */
    public function trainMl(TrainMlTask $task): Response
    {
        return $this->task($task);
    }

    /** 04:00 NPT, Monday — cron_utc: 15 22 * * 0 (Sunday in UTC) */
    public function backtestSignals(BacktestSignalsTask $task): Response
    {
        return $this->task($task);
    }

    /** 04:15 NPT, Monday — cron_utc: 30 22 * * 0 (Sunday in UTC) */
    public function backtestNextClose(BacktestNextCloseTask $task): Response
    {
        return $this->task($task);
    }

    /** On-demand diagnostic — run it when every nepalstock.com fetch starts failing at once. */
    public function verifyNepseToken(VerifyNepseTokenTask $task): Response
    {
        return $this->task($task);
    }

    /**
     * No fixed timing — this is the on-demand, one-stock counterpart to
     * fetch-histories (which handles every stock missing history).
     * Same full-history fetch as the "Fetch Full History" button on that
     * stock's detail page, just callable by URL instead of needing to log
     * into the SPA. Re-running it re-fetches (safe — it's an upsert), it's
     * not limited to stocks that never had one.
     */
    public function fetchHistory(string $symbol, PriceHistorySource $history): Response
    {
        set_time_limit(0);

        $stock = Stock::where('symbol', strtoupper($symbol))->first();

        if (! $stock) {
            return $this->plain("No stock found for symbol [{$symbol}].", 404);
        }

        try {
            $result = $history->fetchHistory($stock);

            return $this->plain(
                "\$ fetch-history {$stock->symbol}\n".
                "{$result['rows_imported']} rows imported ({$result['oldest_date']} to {$result['newest_date']})."
            );
        } catch (Throwable $e) {
            CronTaskFailed::dispatch("fetch-history/{$stock->symbol}", $e->getMessage());

            return $this->plain("Fetch failed for {$stock->symbol}: {$e->getMessage()}", 502);
        }
    }

    public function fetchHistories(FetchHistoriesTask $task): Response
    {
        return $this->task($task);
    }

    public function syncSectors(SyncSectorsTask $task): Response
    {
        return $this->task($task);
    }

    public function syncDividends(SyncDividendsTask $task): Response
    {
        return $this->task($task);
    }

    public function generateAiOpinions(GenerateAiOpinionsTask $task): Response
    {
        return $this->task($task);
    }

    public function syncFundamentals(SyncFundamentalsTask $task): Response
    {
        return $this->task($task);
    }

    private function task(CronTask $task): Response
    {
        return $this->plain($this->tasks->run($task));
    }

    private function plain(string $body, int $status = 200): Response
    {
        return response($body, $status)->header('Content-Type', 'text/plain');
    }
}
