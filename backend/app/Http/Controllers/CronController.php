<?php

namespace App\Http\Controllers;

use App\Tasks\TaskRunner;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Every /cron/* URL lands here. Which task runs is set per route (the
 * 'task' default in routes/web.php, from config/cron.php) — this just
 * hands it to TaskRunner and returns the plain-text log.
 */
class CronController extends Controller
{
    public function __invoke(Request $request, TaskRunner $runner): Response
    {
        $task = app($request->route('task'))->withRequest($request);

        return response($runner->run($task))->header('Content-Type', 'text/plain');
    }
}
