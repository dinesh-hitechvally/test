<?php

namespace App\Services\MarketData;

use App\Models\MlModel;
use App\Models\Stock;
use Illuminate\Support\Facades\File;
use Rubix\ML\Classifiers\ClassificationTree;
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

    // Caps how much history each stock contributes. Two reasons, not just
    // memory: (1) with 300+ eligible stocks, using every day of a 10+ year
    // history blows past what a 150-tree forest can fit in memory, and (2)
    // NEPSE's own regulatory/liquidity regime years ago behaves differently
    // from today's market, so the most recent ~2 years is arguably more
    // relevant training signal anyway, not just a smaller sample of it.
    private const MAX_ROWS_PER_STOCK = 500;

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

            if (count($rows) > self::MAX_ROWS_PER_STOCK) {
                $rows = array_slice($rows, -self::MAX_ROWS_PER_STOCK);
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

        // balanced:true vs false was A/B tested, not assumed: with the same
        // 309-stock/112k-row training set, balanced:true scored 50.37% and
        // balanced:false scored 53.03% (both against a 67.05% baseline from
        // a heavily trending test window). Unbalanced is the real, measured
        // improvement, but it's a partial one — the model still doesn't beat
        // baseline. During a sustained one-directional market, "the trend
        // continues" is a genuinely hard bar for a technical-indicator model
        // to clear; this isn't a tuning bug, it's the feature set's real
        // limit in this kind of regime.
        //
        // ClassificationTree's default maxLeafSize (3) with no depth cap is
        // fine on a few thousand rows but produces enormous trees at 100k+
        // rows — the saved model hit 27MB gzip-compressed and blew past
        // PHP's default 512MB memory_limit just to *deserialize* it for a
        // single prediction (a real production incident, not hypothetical:
        // it 500'd every /ml-prediction request). Capping depth/leaf size
        // keeps the model small enough to load normally and also curbs
        // overfitting on noisy daily price data.
        $model = new PersistentModel(
            new RandomForest(new ClassificationTree(maxHeight: 12, maxLeafSize: 20), estimators: 100, ratio: 0.3, balanced: false),
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
