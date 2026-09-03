<?php

namespace App\Console\Commands;

use App\Services\MarketData\MlDirectionPredictorService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('ml:train-predictor')]
#[Description('Train the direction predictor (Random Forest) on pooled stock history and report its real out-of-sample accuracy')]
class TrainMlPredictor extends Command
{
    public function handle(MlDirectionPredictorService $predictor): int
    {
        $this->info('Training direction predictor...');

        try {
            $model = $predictor->train();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Value'],
            [
                ['Stocks used', $model->stocks_used],
                ['Train samples', $model->train_samples],
                ['Test samples', $model->test_samples],
                ['Accuracy (out-of-sample)', round($model->accuracy * 100, 2).'%'],
                ['Baseline (majority class)', round($model->baseline_accuracy * 100, 2).'%'],
                ['Precision (up)', round($model->precision * 100, 2).'%'],
                ['Recall (up)', round($model->recall * 100, 2).'%'],
                ['F1', round($model->f1, 4)],
            ]
        );

        if ($model->beatsBaseline()) {
            $this->info('Model beats the naive baseline — worth showing.');
        } else {
            $this->warn('Model does NOT beat the naive "always guess majority class" baseline. Still saved (and the UI will say so honestly), but this is not yet adding value over a coin-flip-with-known-bias.');
        }

        return self::SUCCESS;
    }
}
