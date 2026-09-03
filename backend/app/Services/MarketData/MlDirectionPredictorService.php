<?php

namespace App\Services\MarketData;

use App\Models\MlModel;
use App\Models\Stock;
use Illuminate\Support\Facades\File;
use Rubix\ML\Classifiers\RandomForest;
use Rubix\ML\Datasets\Labeled;
use Rubix\ML\Datasets\Unlabeled;
use Rubix\ML\Persisters\Filesystem;
use Rubix\ML\PersistentModel;
use Rubix\ML\Serializers\RBX;
use RuntimeException;

/**
 * Binary direction classifier ("will this stock's close be higher N trading
 * days from now?") using a Random Forest over engineered technical features
 * (see MlFeatureBuilder). Trained on data pooled across every stock with
 * enough history, validated with a chronological (not random) train/test
 * split so the reported accuracy is a genuine out-of-sample measurement,
 * never a hardcoded claim.
 */
class MlDirectionPredictorService
{
    private const HORIZON_DAYS = 5;

    private const MIN_ROWS_PER_STOCK = 60;

    private const TEST_SPLIT = 0.2;

    private const MODEL_RELATIVE_PATH = 'ml/direction_model.rbx';

    public function __construct(private readonly MlFeatureBuilder $features) {}

    public function train(): MlModel
    {
        $stocks = Stock::has('dailyPrices', '>=', 260)->get();

        $examples = [];
        $stocksUsed = 0;

        foreach ($stocks as $stock) {
            $data = $this->features->buildTrainingData($stock);
            $rows = $data['rows'];
            $closes = $data['closes'];

            if (count($rows) < self::MIN_ROWS_PER_STOCK) {
                continue;
            }

            $usedAny = false;

            foreach ($rows as $row) {
                $futureIndex = $row['index'] + self::HORIZON_DAYS;

                if ($futureIndex >= count($closes)) {
                    continue; // no future close yet to label this row with
                }

                $examples[] = [
                    'date' => $row['date'],
                    'features' => $row['features'],
                    'label' => $closes[$futureIndex] > $row['close'] ? 'up' : 'down',
                ];
                $usedAny = true;
            }

            if ($usedAny) {
                $stocksUsed++;
            }
        }

        if (count($examples) < 200) {
            throw new RuntimeException(
                'Not enough training data yet ('.count($examples)." examples across {$stocksUsed} stocks) — ".
                'fetch full history for more stocks first (need 260+ days of price history per stock).'
            );
        }

        File::ensureDirectoryExists(dirname($this->modelPath()));

        usort($examples, fn ($a, $b) => $a['date'] <=> $b['date']);

        $splitIndex = (int) floor(count($examples) * (1 - self::TEST_SPLIT));
        $train = array_slice($examples, 0, $splitIndex);
        $test = array_slice($examples, $splitIndex);

        $model = new PersistentModel(
            new RandomForest(estimators: 150, ratio: 0.3, balanced: true),
            new Filesystem($this->modelPath()),
            new RBX()
        );

        $model->train(Labeled::build(array_column($train, 'features'), array_column($train, 'label')));

        $predictions = $model->predict(Unlabeled::build(array_column($test, 'features')));
        $testLabels = array_column($test, 'label');
        [$accuracy, $precision, $recall, $f1] = $this->evaluate($predictions, $testLabels);

        // The bar the model actually has to clear: just guessing whichever
        // class was more common in the test period.
        $upCount = count(array_filter($testLabels, fn ($l) => $l === 'up'));
        $baselineAccuracy = max($upCount, count($testLabels) - $upCount) / count($testLabels);

        $model->save();

        return MlModel::create([
            'name' => 'direction_predictor',
            'horizon_days' => self::HORIZON_DAYS,
            'train_samples' => count($train),
            'test_samples' => count($test),
            'accuracy' => $accuracy,
            'baseline_accuracy' => $baselineAccuracy,
            'precision' => $precision,
            'recall' => $recall,
            'f1' => $f1,
            'stocks_used' => $stocksUsed,
            'model_path' => self::MODEL_RELATIVE_PATH,
            'trained_at' => now(),
        ]);
    }

    /**
     * @return array{direction: string, probability: float, as_of_date: string}|null
     */
    public function predict(Stock $stock): ?array
    {
        if (! file_exists($this->modelPath())) {
            return null;
        }

        $row = $this->features->latestFeatureRow($stock);

        if ($row === null) {
            return null;
        }

        $model = PersistentModel::load(new Filesystem($this->modelPath()), new RBX());
        $proba = $model->proba(Unlabeled::build([$row['features']]));
        $upProbability = $proba[0]['up'] ?? 0.0;

        return [
            'direction' => $upProbability >= 0.5 ? 'up' : 'down',
            'probability' => round(max($upProbability, 1 - $upProbability), 4),
            'as_of_date' => $row['date'],
        ];
    }

    public function latestMetrics(): ?MlModel
    {
        return MlModel::latest('trained_at')->first();
    }

    /**
     * @param  string[]  $predictions
     * @param  string[]  $actual
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function evaluate(array $predictions, array $actual): array
    {
        $tp = $fp = $tn = $fn = 0;

        foreach ($predictions as $i => $predicted) {
            $isUp = $actual[$i] === 'up';
            $predictedUp = $predicted === 'up';

            match (true) {
                $predictedUp && $isUp => $tp++,
                $predictedUp && ! $isUp => $fp++,
                ! $predictedUp && ! $isUp => $tn++,
                default => $fn++,
            };
        }

        $total = count($predictions);
        $accuracy = $total > 0 ? ($tp + $tn) / $total : 0.0;
        $precision = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : 0.0;
        $recall = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : 0.0;
        $f1 = ($precision + $recall) > 0 ? (2 * $precision * $recall) / ($precision + $recall) : 0.0;

        return [$accuracy, $precision, $recall, $f1];
    }

    private function modelPath(): string
    {
        return storage_path('app/'.self::MODEL_RELATIVE_PATH);
    }
}
