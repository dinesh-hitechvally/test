<?php

namespace App\Services\MachineLearning;

/** Starts the model training in a separate background process, so the request that asked for it can end at once. */
interface MlTrainingLauncher
{
    /** Null once the process is started; otherwise why it could not be (shown to the person running the cron). */
    public function launch(): ?string;
}
