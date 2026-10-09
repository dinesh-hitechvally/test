<?php

namespace App\Console\Commands;

use App\Services\MachineLearning\MlTrainingRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Trains the direction model here and now, with no request time limit. The /cron/generate/ml-model URL starts this in
 * the background (it cannot wait for it); run it directly, or from a cPanel cron job, when background processes are
 * not allowed on the server.
 */
#[Signature('ml:train')]
#[Description('Train the price-direction model on every stock with enough history (takes several minutes).')]
class TrainMlCommand extends Command
{
    public function handle(MlTrainingRunner $runner): int
    {
        $this->info('Training the direction model — this takes several minutes ...');

        try {
            $model = $runner->runNow();
        } catch (Throwable $e) {
            // The runner has already recorded the failure where the cron URL reports it.
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line($runner->summary($model));

        return self::SUCCESS;
    }
}
