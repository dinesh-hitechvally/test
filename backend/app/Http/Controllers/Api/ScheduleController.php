<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

/**
 * Introspects the actual registered Laravel schedule (routes/console.php)
 * rather than a hand-maintained copy — so this can never drift out of sync
 * the way a manually-written list would.
 */
class ScheduleController extends Controller
{
    private const DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    public function index(Schedule $schedule)
    {
        // routes/console.php only runs when Laravel boots through the
        // console kernel (artisan commands) — a normal web request never
        // loads it, so the Schedule singleton would otherwise be empty
        // here. Requiring it directly registers its Schedule::command(...)
        // calls onto this same shared instance.
        if (count($schedule->events()) === 0) {
            require base_path('routes/console.php');
        }

        $events = collect($schedule->events())
            ->map(function ($event) {
                if (! preg_match('/"artisan"\s+(.+)$/', $event->command, $m)) {
                    return null;
                }

                $commandLine = trim($m[1]);
                $commandName = strtok($commandLine, ' ');
                $registered = Artisan::all();

                return [
                    'command' => $commandLine,
                    'expression' => $event->expression,
                    'timezone' => (string) ($event->timezone ?: config('app.timezone')),
                    'schedule_description' => $this->describeCron($event->expression),
                    'description' => isset($registered[$commandName]) ? $registered[$commandName]->getDescription() : null,
                ];
            })
            ->filter()
            ->values();

        return response()->json($events);
    }

    /**
     * Translates the handful of cron shapes this app's own schedule
     * actually uses into plain English. Not a general-purpose cron parser —
     * falls back to the raw expression for anything it doesn't recognize,
     * which is always accurate even when not pretty.
     */
    private function describeCron(string $expression): string
    {
        $parts = preg_split('/\s+/', trim($expression));
        if (count($parts) !== 5) {
            return $expression;
        }

        [$minute, $hour, $dayOfMonth, $month, $dayOfWeek] = $parts;

        if (str_starts_with($minute, '*/') && $hour === '*' && $dayOfMonth === '*' && $month === '*' && $dayOfWeek === '*') {
            return "Every {$this->afterSlash($minute)} minutes";
        }

        if (is_numeric($minute) && is_numeric($hour) && $dayOfMonth === '*' && $month === '*') {
            $time = $this->formatTime((int) $hour, (int) $minute);

            if ($dayOfWeek === '*') {
                return "Daily at {$time}";
            }
            if ($dayOfWeek === '1-5') {
                return "Weekdays at {$time}";
            }
            if (is_numeric($dayOfWeek)) {
                $day = self::DAY_NAMES[(int) $dayOfWeek] ?? $dayOfWeek;

                return "Weekly, {$day} at {$time}";
            }
        }

        return $expression;
    }

    private function afterSlash(string $value): string
    {
        return explode('/', $value)[1] ?? $value;
    }

    private function formatTime(int $hour, int $minute): string
    {
        return sprintf('%02d:%02d', $hour, $minute);
    }
}
