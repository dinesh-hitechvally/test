<?php

namespace App\Http\Cron;

/**
 * Reads config/cron.php — the list of /cron/<path> URLs and which task each
 * runs — for the two places that need it: routes/web.php (to register the
 * routes) and the Data Source Settings page (to list the scheduled ones).
 */
final class CronSchedule
{
    /** @return array<string, array{task: class-string, when?: string, cron_npt?: string, cron_utc?: string}> */
    public static function tasks(): array
    {
        return config('cron.tasks');
    }

    /**
     * The scheduled tasks, ready to paste into a pinger — shape the Data
     * Source Settings page reads ('command' is the task's name).
     *
     * @return list<array<string, ?string>>
     */
    public function scheduled(): array
    {
        $secret = config('services.cron.secret');

        return collect(self::tasks())
            ->filter(fn ($entry) => isset($entry['when']))
            ->map(function ($entry, $path) use ($secret) {
                $task = app($entry['task']);

                return [
                    'command' => $task->name(),
                    'group' => strtok($path, '/'),
                    'url' => url('/cron/'.$path).($secret ? '?key='.$secret : ''),
                    'when' => $entry['when'],
                    'cron_npt' => $entry['cron_npt'],
                    'cron_utc' => $entry['cron_utc'],
                    'description' => $task->description(),
                ];
            })
            ->values()
            ->all();
    }
}
