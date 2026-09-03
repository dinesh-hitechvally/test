<?php

namespace App\Console\Commands;

use App\Models\ForecastModel;
use App\Models\Stock;
use App\Services\MarketData\HoltForecastService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('forecast:backtest')]
#[Description('Walk-forward backtest the Holt forecast method and report its real out-of-sample MAPE/directional accuracy')]
class BacktestForecast extends Command
{
    /** Matches ForecastService::TRADING_DAYS_AHEAD. */
    private const HORIZON_DAYS = 22;

    public function handle(HoltForecastService $holt): int
    {
        $stocks = Stock::has('dailyPrices', '>=', 260)->get();

        $allResults = [];
        $stocksUsed = 0;

        $bar = $this->output->createProgressBar($stocks->count());
        $bar->start();

        foreach ($stocks as $stock) {
            $closes = $stock->dailyPrices()
                ->orderBy('trade_date')
                ->pluck('close_price')
                ->map(fn ($v) => (float) $v)
                ->values()
                ->all();

            if (count($closes) >= 100) {
                $results = $holt->backtestOne($closes, self::HORIZON_DAYS, step: 5);

                if ($results !== []) {
                    array_push($allResults, ...$results);
                    $stocksUsed++;
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if (count($allResults) < 20) {
            $this->error('Not enough backtest points ('.count($allResults).') — need more stocks with full history.');

            return self::FAILURE;
        }

        $mape = array_sum(array_column($allResults, 'pct_error')) / count($allResults);
        $directionalAccuracy = count(array_filter($allResults, fn ($r) => $r['correct_direction'])) / count($allResults);

        $model = ForecastModel::create([
            'method' => 'holt_linear_trend',
            'horizon_days' => self::HORIZON_DAYS,
            'test_points' => count($allResults),
            'mape' => $mape,
            'directional_accuracy' => $directionalAccuracy,
            'stocks_used' => $stocksUsed,
            'computed_at' => now(),
        ]);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Stocks used', $model->stocks_used],
                ['Test points', $model->test_points],
                ['MAPE (out-of-sample)', round($model->mape * 100, 2).'%'],
                ['Directional accuracy', round($model->directional_accuracy * 100, 2).'%'],
            ]
        );

        if ($model->beatsCoinFlip()) {
            $this->info('Directional accuracy beats a 50/50 coin flip.');
        } else {
            $this->warn('Directional accuracy does NOT beat a 50/50 coin flip. Still saved (and the UI will say so honestly).');
        }

        return self::SUCCESS;
    }
}
