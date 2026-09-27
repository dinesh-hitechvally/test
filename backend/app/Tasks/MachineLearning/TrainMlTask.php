<?php

namespace App\Tasks\MachineLearning;

use App\Services\MachineLearning\MlDirectionPredictorService;
use App\Tasks\Task;

class TrainMlTask extends Task
{
    public function __construct(private readonly MlDirectionPredictorService $predictor) {}

    public function name(): string
    {
        return 'ml:train-predictor';
    }

    public function description(): string
    {
        return 'Train the direction predictor (Random Forest) on pooled stock history and report its real out-of-sample accuracy';
    }

    public function logFile(): string
    {
        return 'ml-train.log';
    }

    public function handle(): string
    {
        // The training set is every qualifying stock's full price/indicator
        // history loaded into one in-memory Rubix ML dataset (not streamed),
        // which exceeds PHP's default 512M limit. Raised only for this run,
        // not in php.ini, since normal requests don't need that ceiling.
        ini_set('memory_limit', '2048M');

        $model = $this->predictor->train();

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
}
