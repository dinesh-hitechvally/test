<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
            // Separate from the OAuth bot token above (unused, no package
            // installed for it) — this is a plain Slack "Incoming Webhook"
            // URL, posted to directly over HTTP by CronAlertService with no
            // package needed. Leave unset to skip Slack alerts entirely.
            'webhook_url' => env('SLACK_WEBHOOK_URL'),
        ],
    ],

    'cron' => [
        // Shared secret for the URL-triggered routes in routes/web.php
        // (replaces the Laravel scheduler/server cron) — set this in .env
        // and paste the same value into whatever external service pings
        // these URLs (cron-job.org, UptimeRobot, etc.) as ?key=...
        'secret' => env('CRON_SECRET'),

        // Where CronAlertService emails a failing job (via whatever
        // MAIL_MAILER is configured — defaults to just logging the email
        // rather than sending it, until real SMTP is set up). Leave unset
        // to skip email alerts entirely.
        'alert_email' => env('CRON_ALERT_EMAIL'),
    ],

    'gemini' => [
        // Free-tier Google AI Studio key (https://aistudio.google.com/apikey)
        // — powers the "AI Opinion" buy/sell/hold lens on the Stock Detail
        // and Analyst Report pages. Leave unset to skip the feature entirely
        // (the endpoint just returns "not configured", no error).
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.6-flash'),
    ],

];
