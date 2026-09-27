<?php

namespace App\Http\Controllers;

use App\Services\Cron\CronTaskRunner;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Every /cron/* URL lands here. Which task runs is set per route (the
 * 'task' default in routes/web.php, from CronSchedule::TASKS) — this just
 * hands it to CronTaskRunner and returns the plain-text log.
 */
class CronController extends Controller
{
    public function __invoke(Request $request, CronTaskRunner $runner): Response
    {
        $task = app($request->route('task'))->withRequest($request);

        return response($runner->run($task))->header('Content-Type', 'text/plain');
    }
}
