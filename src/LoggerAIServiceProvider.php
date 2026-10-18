<?php

namespace LoggerAI\Laravel;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use LoggerAI\Laravel\Console\FlushCommand;
use LoggerAI\Laravel\Console\HeartbeatCommand;
use LoggerAI\Laravel\Console\InstallCommand;
use LoggerAI\Laravel\Console\TestCommand;
use LoggerAI\Laravel\Http\Middleware\TrackRequests;
use LoggerAI\Laravel\Jobs\SendBatch;
use LoggerAI\Laravel\Support\Redactor;
use Throwable;

/**
 * Discovered automatically by Laravel. Times requests, queries, outgoing HTTP,
 * Redis, mail, queue jobs and commands; reports exceptions and logs; serves
 * the browser session script. Does nothing until LOGGERAI_KEY is set.
 */
final class LoggerAIServiceProvider extends ServiceProvider
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'notice' => 1, 'warning' => 2, 'error' => 3, 'critical' => 4, 'alert' => 4, 'emergency' => 4];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/loggerai.php', 'loggerai');

        $this->app->singleton(Recorder::class, fn ($app) => new Recorder(
            new Redactor((array) $app['config']->get('loggerai.redact', [])), $app->basePath()));
        $this->app->singleton(Transport::class, fn ($app) => new Transport(
            (array) $app['config']->get('loggerai'), $app->storagePath('loggerai')));
        $this->app->singleton(LoggerAI::class, fn ($app) => new LoggerAI(
            $app, $app->make(Recorder::class), $app->make(Transport::class), (array) $app['config']->get('loggerai')));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/loggerai.php' => config_path('loggerai.php')], 'loggerai-config');
            $this->commands([InstallCommand::class, TestCommand::class, FlushCommand::class, HeartbeatCommand::class]);
        }

        // The browser script, served by the app itself (works on any host, no publishing).
        $this->app['router']->get('loggerai.js', function () {
            return response()->file(__DIR__.'/../resources/js/loggerai.js', [
                'Content-Type' => 'application/javascript; charset=utf-8',
                'Cache-Control' => 'public, max-age=86400',
            ]);
        })->name('loggerai.script');

        Blade::directive('loggerai', fn () => "<?php echo app(\\LoggerAI\\Laravel\\LoggerAI::class)->scriptTag(); ?>");

        $loggerai = $this->app->make(LoggerAI::class);
        if (! $loggerai->enabled()) {
            return;
        }
        $capture = (array) $this->app['config']->get('loggerai.capture', []);

        if ($this->app->bound(HttpKernel::class)) {
            $kernel = $this->app->make(HttpKernel::class);
            if (method_exists($kernel, 'prependMiddleware')) {
                $kernel->prependMiddleware(TrackRequests::class);  // first in, last out: times the whole stack
            }
        }

        $events = $this->app['events'];
        $recorder = $loggerai->recorder();
        $transport = $loggerai->transport();

        // The route pattern as soon as it's known, so errors and logs carry it too.
        $events->listen(RouteMatched::class, function (RouteMatched $e) use ($recorder) {
            $recorder->setRoute('/'.ltrim($e->route->uri(), '/'));
        });

        if ($capture['queries'] ?? true) {
            $events->listen(QueryExecuted::class, function (QueryExecuted $q) use ($recorder) {
                $driver = $q->connection->getDriverName();
                $target = ['pgsql' => 'postgres', 'sqlsrv' => 'sqlserver'][$driver] ?? $driver;
                $verb = strtoupper(strtok(ltrim($q->sql), " \n\t(") ?: '?');
                $recorder->dependency($target, substr($verb, 0, 20), (float) $q->time);
            });
        }

        if ($capture['http_client'] ?? true) {
            $events->listen(ResponseReceived::class, function (ResponseReceived $e) use ($recorder, $transport) {
                if ($transport->sending) {
                    return;
                }
                $stats = method_exists($e->response, 'handlerStats') ? $e->response->handlerStats() : [];
                $ms = isset($stats['total_time']) ? (float) $stats['total_time'] * 1000 : 0.0;
                $recorder->dependency($this->host($e->request->url()), $e->request->method(), $ms, $e->response->status() < 500);
            });
            $events->listen(ConnectionFailed::class, function (ConnectionFailed $e) use ($recorder) {
                $recorder->dependency($this->host($e->request->url()), $e->request->method(), 0.0, false);
            });
        }

        if (($capture['redis'] ?? true) && $this->app->bound('redis') && $this->app['config']->get('database.redis')) {
            try {
                $this->app['redis']->enableEvents();
                $events->listen(CommandExecuted::class, function (CommandExecuted $c) use ($recorder) {
                    $recorder->dependency('redis', strtoupper($c->command), (float) $c->time);
                });
            } catch (Throwable) {
                // No usable Redis connection configured: nothing to time.
            }
        }

        if ($capture['mail'] ?? true) {
            $sending = [];
            $events->listen(MessageSending::class, function () use (&$sending) {
                $sending[] = microtime(true);
            });
            $events->listen(MessageSent::class, function () use (&$sending, $recorder) {
                $t = array_pop($sending);
                $mailer = (string) $this->app['config']->get('mail.default', 'mail');
                $recorder->dependency('mail:'.$mailer, 'SEND', $t ? (microtime(true) - $t) * 1000 : 0.0);
            });
        }

        if ($capture['jobs'] ?? true) {
            $events->listen(JobProcessing::class, function (JobProcessing $e) use ($recorder) {
                if (! $this->ours($e->job)) {
                    $recorder->start('job', 'JOB');
                    $recorder->setRoute($e->job->resolveName());
                }
            });
            $events->listen(JobProcessed::class, function (JobProcessed $e) use ($recorder, $loggerai) {
                if ($recorder->active() && ! $this->ours($e->job)) {
                    $recorder->finish($e->job->hasFailed() ? 500 : 200);
                    $loggerai->flush();
                }
            });
            $failed = function ($e) use ($recorder, $loggerai) {
                if ($recorder->active() && ! $this->ours($e->job)) {
                    $recorder->finish(500);
                    $loggerai->flush();
                }
            };
            $events->listen(JobFailed::class, $failed);
            $events->listen(JobExceptionOccurred::class, $failed);
        }

        if ($capture['commands'] ?? true) {
            $events->listen(CommandStarting::class, function (CommandStarting $e) use ($recorder) {
                if ($e->command && ! $this->ignoredCommand($e->command)) {
                    $recorder->start('command', 'CLI');
                    $recorder->setRoute($e->command);
                }
            });
            $events->listen(CommandFinished::class, function (CommandFinished $e) use ($recorder, $loggerai) {
                if ($e->command && $recorder->active() && $recorder->route() === $e->command) {
                    $recorder->finish($e->exitCode === 0 ? 200 : 500);
                    $loggerai->flush(true);
                }
            });
        }

        if ($capture['exceptions'] ?? true) {
            $this->app->afterResolving(ExceptionHandler::class, function ($handler) use ($recorder) {
                if (method_exists($handler, 'reportable')) {
                    $handler->reportable(function (Throwable $e) use ($recorder) {
                        $status = method_exists($e, 'getStatusCode') ? (int) $e->getStatusCode() : 500;
                        $recorder->exception($e, $status);
                    });
                }
            });
        }

        $minLevel = $capture['logs'] ?? 'warning';
        if ($minLevel) {
            $min = self::LEVELS[strtolower((string) $minLevel)] ?? 2;
            $events->listen(MessageLogged::class, function (MessageLogged $e) use ($recorder, $min) {
                if ((self::LEVELS[$e->level] ?? 0) < $min || isset($e->context['exception'])) {
                    return;  // exceptions are reported with their stack trace instead
                }
                $recorder->log($e->level === 'warning' ? 'WARNING' : (in_array($e->level, ['critical', 'alert', 'emergency'], true) ? 'CRITICAL' : strtoupper($e->level)), (string) $e->message);
            });
        }

        // Anything recorded outside a request or job (a script, a failed boot) still goes out.
        $this->app->terminating(function () use ($loggerai) {
            $loggerai->flush($this->app->runningInConsole());
        });
    }

    private function host(string $url): string
    {
        return parse_url($url, PHP_URL_HOST) ?: 'unknown';
    }

    private function ours($job): bool
    {
        return $job->resolveName() === SendBatch::class;
    }

    private function ignoredCommand(string $command): bool
    {
        foreach ((array) $this->app['config']->get('loggerai.ignore.commands', []) as $pattern) {
            if (Str::is($pattern, $command)) {
                return true;
            }
        }

        return false;
    }
}
