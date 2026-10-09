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

    // Websites that can block us (a web filter, an HTTP 403 / 429 ...). While one is blocking, every request to it is
    // skipped — see App\Services\DataSources\SourceBlockRegistry and the source_blocks table. Add a website here
    // to track it too; others are never blocked or tracked.
    'source_block' => [
        'websites' => ['nepalstock.com', 'sharesansar.com', 'merolagani.com'],
        'cooldown_minutes' => (int) env('SOURCE_BLOCK_COOLDOWN_MINUTES', 30), // pause after a block, then the next request retries
        'transient_failures' => 3, // failures in a row (outage, not refusal) that also block a website
    ],

    // Where the PHP command-line binary is, for the background ML training (see BackgroundArtisanLauncher). Leave unset
    // to detect it; set it when the web server's PHP is FPM/CGI and detection picks the wrong one.
    'ml' => [
        'php_binary' => env('ML_PHP_BINARY'),
    ],

    'nepse' => [
        // nepalstock.com does not send its intermediate certificate, so PHP builds without a full CA chain (e.g. local
        // Laragon) fail with "cURL error 60". Set NEPSE_VERIFY_SSL=false to skip the check for NEPSE calls only.
        'verify_ssl' => env('NEPSE_VERIFY_SSL', true),
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
            // URL, posted to directly over HTTP by FailureAlertService with no
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

        // Where FailureAlertService emails a failing job (via whatever
        // MAIL_MAILER is configured — defaults to just logging the email
        // rather than sending it, until real SMTP is set up). Leave unset
        // to skip email alerts entirely.
        'alert_email' => env('CRON_ALERT_EMAIL'),
    ],

    'groq' => [
        // Free-tier key (https://console.groq.com/keys, no billing setup)
        // — powers the "AI Opinion" buy/sell/hold lens on the Stock Detail
        // and Analyst Report pages. Third provider this app has used here
        // (Gemini, then Claude, now Groq — see git history). Leave unset
        // to skip the feature entirely (the endpoint just returns "not
        // configured", no error).
        'api_key' => env('GROQ_API_KEY'),
        'model' => env('GROQ_MODEL', 'openai/gpt-oss-120b'),
    ],

];
