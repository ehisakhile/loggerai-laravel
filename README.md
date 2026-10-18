# LoggerAI for Laravel

Performance monitoring, errors, logs, alerts and user sessions for Laravel,
with AI that explains what went wrong. Install it, add your key, deploy.

- **Every route** timed under its pattern (`/orders/{order}`): p50/p95/p99,
  Apdex, status codes, slow and failed requests.
- **What each request waits on**: database queries (with a warning when a page
  runs one query per row), Laravel HTTP client calls, Redis, mail.
- **Queue jobs and Artisan commands**, timed like requests.
- **Exceptions**, grouped by cause, with stack traces, the release they
  started in and how many users they hit.
- **Logs** at warning and above.
- **User sessions**: pages, clicks, requests and browser errors, linked to the
  exact server request each click caused.
- **Alerts in Slack** (slow API, low Apdex, errors, traffic drop, new errors)
  and **insights**, set up for you in the LoggerAI dashboard.

Passwords, tokens, cookies, API keys and card numbers are masked before
anything leaves your server or your users' browsers. No request bodies are
sent from the server, and users are counted by a one-way hash of their id,
never their name or email.

Laravel 10, 11, 12 and 13 on PHP 8.1+. Works on a VPS, Laravel Forge,
Docker, Laravel Vapor and shared hosting.

## Install

```bash
composer require loggerai/laravel
php artisan loggerai:install
```

The installer asks for your project's keys (LoggerAI → your project →
**Connect**), adds them to `.env` and sends a test. Deploy, and data shows up
within a minute.

Or set them yourself:

```dotenv
LOGGERAI_KEY=sk_live_...          # secret: server only
LOGGERAI_PUBLIC_KEY=pk_live_...   # public: records browser sessions
LOGGERAI_RELEASE=${GIT_SHA}       # optional: "since release X"
```

Check the connection any time with `php artisan loggerai:test`.

## Where it sends from

Nothing is sent while a user waits.

| Hosting | What happens | Set |
|---|---|---|
| VPS, Forge, Docker | Records go to `storage/loggerai`; a batch leaves every few seconds, after a response has been sent | nothing (default) |
| Shared hosting / cPanel | The same, plus a scheduled flush so quiet sites don't wait for the next visitor | schedule `loggerai:flush` (below) |
| Laravel Vapor | Sent at the end of each request (Lambda can't keep a buffer) | nothing (detected) |
| Busy apps with a queue worker | Batches handed to your queue | `LOGGERAI_TRANSPORT=queue` (and `LOGGERAI_QUEUE=...`) |

For quiet or shared-hosting sites, and to get health checks, add to your
scheduler (`routes/console.php` on Laravel 11+, `app/Console/Kernel.php`
before):

```php
Schedule::command('loggerai:flush')->everyMinute();
Schedule::command('loggerai:heartbeat')->everyMinute();   // database and cache health checks
```

## User sessions

With `LOGGERAI_PUBLIC_KEY` set, a small script (`/loggerai.js`, served by
your app) is added before `</head>` on HTML pages. It records pages, clicks,
form submits (never what's typed), requests and browser errors, and tags
requests to your own domain so LoggerAI can link them to the server request
they caused.

- Your API on another domain? List it so it gets the trace headers too (and
  allow the `traceparent` and `x-loggerai-session` headers in its CORS):
  `LOGGERAI_TRACE_HOSTS=api.example.com`
- Place the script yourself: set `'inject' => false` in `config/loggerai.php`
  and put `@loggerai` in your layout's `<head>`.
- A **Report a problem** button for your users: `LOGGERAI_REPORT_BUTTON=true`,
  or call `window.LoggerAI.report('what went wrong')` from your own UI.
- Content Security Policy: allow your LoggerAI endpoint in `connect-src`.
- Mark anything that shouldn't be recorded with `data-loggerai-ignore`.
- Turn sessions off: `LOGGERAI_SESSIONS=false`.

## Calls the package can't see

Laravel's `Http` facade, the database, Redis and mail are timed for you. SDKs
with their own HTTP client (Stripe, AWS, …) aren't; wrap the calls that
matter:

```php
use LoggerAI\Laravel\Facades\LoggerAI;

$intent = LoggerAI::measure('api.stripe.com', 'POST', fn () => $stripe->paymentIntents->confirm($id));
```

Report an exception you've handled but still want to see:

```php
LoggerAI::report($e, ['order' => $order->id]);
```

## Configuration

`php artisan vendor:publish --tag=loggerai-config` writes
`config/loggerai.php`. The main settings:

| `.env` | Default | |
|---|---|---|
| `LOGGERAI_ENABLED` | `true` | Off without a key, e.g. locally |
| `LOGGERAI_SERVICE` | `app` | Name this app in LoggerAI (`api`, `worker`, …) |
| `LOGGERAI_ENVIRONMENT` | `APP_ENV` | Separates production from staging |
| `LOGGERAI_RELEASE` | — | Version or git SHA |
| `LOGGERAI_TRANSPORT` | `auto` | `buffer`, `sync` or `queue` |
| `LOGGERAI_LOG_LEVEL` | `warning` | Lowest log level sent (`false` to turn off) |
| `LOGGERAI_SESSIONS` | `true` | Browser sessions (needs the public key) |

## Support

dev@loggerai.uk · https://loggerai.uk
