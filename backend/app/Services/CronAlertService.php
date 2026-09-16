<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Failure alerting for the URL-triggered cron pipeline (CronController) —
 * until now a failing job just sat quietly in a log file, the same shape of
 * problem that caused the ~2-day silent outage documented in the README's
 * dev log (nobody noticed until they went looking).
 *
 * Deliberately has no hard dependency on Slack or email actually being set
 * up: it always logs, and only ALSO posts to Slack / sends an email if
 * those are configured (SLACK_WEBHOOK_URL / CRON_ALERT_EMAIL in .env) —
 * unconfigured is a normal, supported state, not an error. A delivery
 * failure (Slack unreachable, mail misconfigured) is swallowed rather than
 * thrown, so an alert going out can never itself break the cron job it's
 * reporting on.
 */
class CronAlertService
{
    public function notifyFailure(string $job, string $detail): void
    {
        Log::error("Cron job failed: {$job}", ['detail' => $detail]);

        $this->postToSlack($job, $detail);
        $this->sendEmail($job, $detail);
    }

    private function postToSlack(string $job, string $detail): void
    {
        $webhookUrl = config('services.slack.notifications.webhook_url');

        if (! $webhookUrl) {
            return;
        }

        try {
            Http::timeout(10)->post($webhookUrl, [
                'text' => "🔴 *Share Market Signals* — cron job failed: `{$job}`\n>{$this->truncate($detail)}",
            ]);
        } catch (Throwable $e) {
            Log::warning('Slack alert delivery failed', ['job' => $job, 'error' => $e->getMessage()]);
        }
    }

    private function sendEmail(string $job, string $detail): void
    {
        $to = config('services.cron.alert_email');

        if (! $to) {
            return;
        }

        try {
            Mail::raw(
                "Cron job failed: {$job}\n\n{$this->truncate($detail)}",
                fn ($message) => $message->to($to)->subject("[Share Market Signals] Cron failed: {$job}")
            );
        } catch (Throwable $e) {
            Log::warning('Email alert delivery failed', ['job' => $job, 'error' => $e->getMessage()]);
        }
    }

    private function truncate(string $detail): string
    {
        return mb_strlen($detail) > 1000 ? mb_substr($detail, 0, 1000).'… (truncated)' : $detail;
    }
}
