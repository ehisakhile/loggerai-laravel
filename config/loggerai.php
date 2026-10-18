<?php

// LoggerAI for Laravel. Only LOGGERAI_KEY is required; everything else has
// a sensible default. Docs: https://loggerai.uk/docs/laravel

return [

    // Your project's secret key (Project → Connect in LoggerAI). Leave empty
    // to turn LoggerAI off, e.g. locally.
    'key' => env('LOGGERAI_KEY'),

    // The public key, used by the browser script that records sessions.
    // Safe to expose: it can only send sessions and page events.
    'public_key' => env('LOGGERAI_PUBLIC_KEY'),

    'enabled' => env('LOGGERAI_ENABLED', true),

    'endpoint' => env('LOGGERAI_ENDPOINT', 'https://loggerapp.ehigiator.com/api/v1/ingest/batch'),

    // How this app shows up in LoggerAI.
    'service' => env('LOGGERAI_SERVICE', 'app'),
    'environment' => env('LOGGERAI_ENVIRONMENT', env('APP_ENV', 'production')),
    // A version or git SHA, so LoggerAI can say "since release X".
    'release' => env('LOGGERAI_RELEASE'),

    // How data leaves your app (never while a user waits):
    //   auto   - sync on Laravel Vapor, buffer everywhere else
    //   buffer - collect in storage/loggerai and send a batch every few
    //            seconds, after the response (VPS, Forge, shared hosting)
    //   sync   - send after each request (serverless)
    //   queue  - hand batches to your queue (needs a running worker)
    'transport' => env('LOGGERAI_TRANSPORT', 'auto'),
    'queue' => env('LOGGERAI_QUEUE'),             // queue name for the queue transport
    'buffer_seconds' => (int) env('LOGGERAI_BUFFER_SECONDS', 10),
    'timeout' => (float) env('LOGGERAI_TIMEOUT', 2.0),

    // What to record.
    'capture' => [
        'requests' => true,
        'queries' => true,
        'http_client' => true,   // calls made with Laravel's Http facade
        'redis' => true,
        'mail' => true,
        'jobs' => true,
        'commands' => true,
        'exceptions' => true,
        'logs' => env('LOGGERAI_LOG_LEVEL', 'warning'),  // lowest level sent; false to turn off
        // A one-way hash of the signed-in user's id, so LoggerAI can count
        // affected users. Never their name or email.
        'user' => true,
    ],

    // Record what users do in the browser (pages, clicks, requests, errors)
    // and link it to the server requests it caused. Needs the public key.
    // The script is added before </head> on HTML pages; set 'inject' to
    // false and put @loggerai in your layout to place it yourself.
    'sessions' => [
        'enabled' => env('LOGGERAI_SESSIONS', true),
        'inject' => true,
        'report_button' => env('LOGGERAI_REPORT_BUTTON', false),
        // Other hosts your pages call (your API on another domain) that
        // should receive the trace headers.
        'trace_hosts' => array_filter(explode(',', (string) env('LOGGERAI_TRACE_HOSTS', ''))),
    ],

    // Routes and commands not worth recording.
    'ignore' => [
        'paths' => ['up', 'health', 'telescope*', 'horizon*', '_debugbar*', 'livewire/livewire.js*', 'loggerai.js'],
        'commands' => ['queue:work', 'queue:listen', 'horizon*', 'schedule:work', 'schedule:run', 'octane:*', 'serve', 'tinker',
            'loggerai:*', 'package:discover', 'vendor:publish', 'config:*', 'route:*', 'view:*', 'event:*', 'optimize*'],
    ],

    // Extra field names to mask (on top of passwords, tokens, cookies,
    // authorization, API keys, card numbers and the like).
    'redact' => [],
];
