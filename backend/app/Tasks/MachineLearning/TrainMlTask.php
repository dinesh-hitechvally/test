<?php

namespace App\Tasks\MachineLearning;

use App\Services\MachineLearning\MlTrainingRunner;
use App\Tasks\Task;
use Illuminate\Http\Request;

/**
 * Starts the model training in the background and reports on it. Training one model on every stock's history takes
 * minutes — longer than a web request may last, which is why this URL used to time out — so the URL only starts it
 * (or says it is already running, or shows the last result) and returns at once. See MlTrainingRunner.
 */
class TrainMlTask extends Task
{
    private bool $force = false;

    public function __construct(private readonly MlTrainingRunner $training) {}

    public function withRequest(Request $request): static
    {
        $this->force = $request->boolean('force');

        return $this;
    }

    public function handle(): string
    {
        return $this->training->request($this->force);
    }
}
