<?php

namespace App\Services\MachineLearning;

use App\Models\MlModel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Trains the direction model without tying up a web request.
 *
 * Training reads every stock's history and fits a 100-tree forest on all of it — minutes of work, far past any
 * gateway's timeout, so the cron URL would just time out. Instead the URL only asks request() to START it: that
 * launches `php artisan ml:train` (runNow()) in a background process and returns at once. The progress and outcome
 * live in storage/app/ml/training.json, so pinging the URL again reports "still running" / the result instead of
 * starting a second run.
 *
 * state: running | done | failed
 */
class MlTrainingRunner
{
    /** A "running" state older than this is a process that died without reporting; a new run may start. */
    private const STALE_AFTER_MINUTES = 90;

    /** A run that finished more recently than this is reported, not repeated, unless forced. */
    private const MIN_HOURS_BETWEEN_RUNS = 6;

    public function __construct(
        private readonly MlDirectionPredictorService $predictor,
        private readonly MlTrainingLauncher $launcher,
    ) {}

    /** What the cron URL does: report the current state, or start a background run. Returns the text to show. */
    public function request(bool $force = false): string
    {
        $state = $this->state();

        if ($this->isRunning($state)) {
            return sprintf('Training is already running in the background (started %s). Ping again later for the result.', Carbon::parse($state['started_at'])->diffForHumans());
        }

        if (! $force && ($state['state'] ?? null) === 'done' && Carbon::parse($state['finished_at'])->gt(now()->subHours(self::MIN_HOURS_BETWEEN_RUNS))) {
            return sprintf("Nothing started — the model was trained %s.\n%s\nAdd ?force=1 to train again now.", Carbon::parse($state['finished_at'])->diffForHumans(), $state['message']);
        }

        $previousFailure = ($state['state'] ?? null) === 'failed' ? "The previous run failed: {$state['message']}\n" : '';

        $this->write(['state' => 'running', 'started_at' => now()->toIso8601String(), 'finished_at' => null, 'message' => null]);

        if (($error = $this->launcher->launch()) !== null) {
            $this->write(['state' => 'failed', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'message' => $error]);

            throw new RuntimeException($error);
        }

        return $previousFailure.'Training started in the background — it takes several minutes. Ping this URL again to see the result (it will not start a second run while this one is going).';
    }

    /** The training itself; runs in the background process (php artisan ml:train), where there is no request time limit. */
    public function runNow(): MlModel
    {
        set_time_limit(0);
        // The training set is every qualifying stock's full price/indicator history loaded into one in-memory Rubix ML
        // dataset (not streamed), which exceeds PHP's default 512M limit. Raised only for this run.
        ini_set('memory_limit', '2048M');

        $startedAt = $this->state()['started_at'] ?? now()->toIso8601String();
        $this->write(['state' => 'running', 'started_at' => $startedAt, 'finished_at' => null, 'message' => null]);

        try {
            $model = $this->predictor->train();
        } catch (Throwable $e) {
            Log::error('ML training failed', ['error' => $e->getMessage()]);
            $this->write(['state' => 'failed', 'started_at' => $startedAt, 'finished_at' => now()->toIso8601String(), 'message' => $e->getMessage()]);

            throw $e;
        }

        $this->write(['state' => 'done', 'started_at' => $startedAt, 'finished_at' => now()->toIso8601String(), 'message' => $this->summary($model)]);

        return $model;
    }

    public function summary(MlModel $model): string
    {
        $verdict = $model->beatsBaseline()
            ? 'Model beats the naive baseline — worth showing.'
            : 'Model does NOT beat the naive "always guess majority class" baseline. Still saved (and the UI says so honestly).';

        return implode("\n", [
            "Stocks used: {$model->stocks_used}",
            "Train samples: {$model->train_samples}",
            "Test samples: {$model->test_samples}",
            'Accuracy (out-of-sample): '.round($model->accuracy * 100, 2).'%',
            'Baseline (majority class): '.round($model->baseline_accuracy * 100, 2).'%',
            'Precision (up): '.round($model->precision * 100, 2).'%',
            'Recall (up): '.round($model->recall * 100, 2).'%',
            'F1: '.round($model->f1, 4),
            $verdict,
        ]);
    }

    /** @return array{state?: string, started_at?: ?string, finished_at?: ?string, message?: ?string} */
    public function state(): array
    {
        $path = $this->path();

        if (! File::exists($path)) {
            return [];
        }

        return json_decode(File::get($path), true) ?: [];
    }

    private function isRunning(array $state): bool
    {
        return ($state['state'] ?? null) === 'running'
            && Carbon::parse($state['started_at'])->gt(now()->subMinutes(self::STALE_AFTER_MINUTES));
    }

    private function write(array $state): void
    {
        File::ensureDirectoryExists(dirname($this->path()));
        File::put($this->path(), json_encode($state, JSON_PRETTY_PRINT), true);
    }

    private function path(): string
    {
        return storage_path('app/ml/training.json');
    }
}
