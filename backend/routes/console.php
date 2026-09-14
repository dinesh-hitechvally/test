<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// NEPSE trades Sunday-Thursday historically but this app follows the user's
// confirmed current rule: open Mon-Fri, closed Sat-Sun — ->weekdays() matches
// that exactly. Runs ~30 min after the ~15:00 NPT close.
//
// Fetch and recalculate are two separate steps on purpose: this one only
// pulls prices from nepalstock.com (the app's one and only data source) and
// stops — it never computes indicators/signals/ML/forecasts itself, so a
// slow or broken recalculation can't be blamed on (or block) today's fetch.
Schedule::command('market:sync')
    ->weekdays()
    //->at('15:30')
    ->timezone('Asia/Kathmandu')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/market-sync.log'));

// Runs after the fetch above, as its own step — recomputes indicators,
// re-runs the rule-based signal generator and ML predictor, and refreshes
// the forecast for every stock that got a new price today. Re-runnable on
// its own (e.g. after fixing a bug in the pipeline) without re-fetching.
Schedule::command('market:recalculate')
    ->weekdays()
    //->at('15:40')
    ->timezone('Asia/Kathmandu')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/market-recalculate.log'));

Schedule::command('market:sync-index')
    ->weekdays()
    //->at('15:32')
    ->timezone('Asia/Kathmandu')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/market-sync-index.log'));

// Catches any stock that has never had a full-history fetch — most often
// newly-listed symbols the sync above just discovered — and queues it
// (QUEUE_CONNECTION=database) rather than requiring someone to notice and
// click "Fetch Price History" per stock. Runs a few minutes after the sync.
Schedule::command('stocks:queue-missing-history')
    ->weekdays()
    //->at('15:35')
    ->timezone('Asia/Kathmandu')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/queue-missing-history.log'));

// Drains whatever's queued above. No persistent worker process on this box,
// so the scheduler stands in for one: wakes periodically, processes what's
// there, exits (--stop-when-empty) rather than idling. --max-time caps a run
// so a large backlog (e.g. the first time this runs against every existing
// stock) drains gradually across runs instead of one huge blocking process.
Schedule::command('queue:work --queue=default --stop-when-empty --max-time=600 --tries=2')
    ->everyFifteenMinutes()
    //->between('06:00', '23:00')
    ->timezone('Asia/Kathmandu')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/queue-worker.log'));

// Sector data rarely changes; a weekly sweep is enough to fill in symbols
// discovered by daily scrapes since the last run. --all is intentionally
// omitted here (existing sectors aren't worth re-fetching weekly).
Schedule::command('stocks:backfill-sectors')
    //->weeklyOn(1, '03:00')
    ->timezone('Asia/Kathmandu')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/sector-backfill.log'));

// Retrain weekly so the pooled training set grows as more stocks accumulate
// full history and as daily syncs add more days — after the sector sweep.
Schedule::command('ml:train-predictor')
    //->weeklyOn(1, '03:30')
    ->timezone('Asia/Kathmandu')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/ml-train.log'));

// Re-measure the Holt forecast's real accuracy weekly too, same reasoning.
Schedule::command('forecast:backtest')
    //->weeklyOn(1, '03:45')
    ->timezone('Asia/Kathmandu')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/forecast-backtest.log'));

// Same weekly cadence for the rule-based signal engine's own historical
// win rate, so the Signal History / Accuracy page stays current as more
// signal history accumulates.
Schedule::command('signals:backtest-accuracy')
    //->weeklyOn(1, '04:00')
    ->timezone('Asia/Kathmandu')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/signal-accuracy.log'));
