<?php

namespace LoggerAI\Laravel;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * The package's front door (also the LoggerAI facade):
 *
 *   LoggerAI::measure('api.stripe.com', 'POST', fn () => $stripe->paymentIntents->confirm($id));
 *   LoggerAI::report($e);            // a handled exception you still want to see
 *   LoggerAI::flush();               // send now (e.g. at the end of a long script)
 */
final class LoggerAI
{
    public const VERSION = '0.1.0';

    public function __construct(private Application $app, private Recorder $recorder, private Transport $transport, private array $config)
    {
    }

    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? true) && ! empty($this->config['key']);
    }

    public function recorder(): Recorder
    {
        return $this->recorder;
    }

    public function transport(): Transport
    {
        return $this->transport;
    }

    public function config(?string $key = null, $default = null)
    {
        return $key === null ? $this->config : data_get($this->config, $key, $default);
    }

    /** Time a call the package can't see on its own (an SDK with its own HTTP client, say). */
    public function measure(string $target, string $operation, Closure $call)
    {
        $t = microtime(true);
        $ok = false;
        try {
            $result = $call();
            $ok = true;

            return $result;
        } finally {
            $this->recorder->dependency($target, strtoupper($operation), (microtime(true) - $t) * 1000, $ok);
        }
    }

    public function report(Throwable $e, array $context = []): void
    {
        if ($this->enabled()) {
            $this->recorder->exception($e, null, $context);
        }
    }

    /** Hand what's recorded to the transport (end of a request, job or command). */
    public function flush(bool $force = false): void
    {
        if (! $this->enabled()) {
            return;
        }
        $parts = $this->recorder->drain();
        if ($parts) {
            $this->transport->send($parts);
        } elseif ($this->transport->mode() === 'buffer') {
            $this->transport->flushIfDue($force);
        }
        if ($force && $this->transport->mode() === 'buffer') {
            $this->transport->flushIfDue(true);
        }
    }

    /** A one-way hash of the signed-in user's id: enough to count affected users, nothing more. */
    public function userRef(): ?string
    {
        if (! ($this->config['capture']['user'] ?? true)) {
            return null;
        }
        try {
            $id = $this->app['auth']->guard()->id();
        } catch (Throwable) {
            return null;
        }
        if ($id === null) {
            return null;
        }

        return substr(hash_hmac('sha256', (string) $id, (string) $this->app['config']->get('app.key', 'loggerai')), 0, 16);
    }

    /** The <script> tag that records browser sessions (also the @loggerai Blade directive). */
    public function scriptTag(): string
    {
        $pk = $this->config['public_key'] ?? null;
        if (! $pk || ! ($this->config['sessions']['enabled'] ?? true)) {
            return '';
        }
        $attrs = [
            'src' => '/loggerai.js?v='.self::VERSION,
            'data-key' => $pk,
            'data-endpoint' => $this->config['endpoint'],
            'data-trace' => $this->recorder->traceId() ?? '',
            'data-trace-hosts' => implode(',', $this->config['sessions']['trace_hosts'] ?? []),
            'data-report-button' => ($this->config['sessions']['report_button'] ?? false) ? 'true' : 'false',
            'data-release' => (string) ($this->config['release'] ?? ''),
        ];
        $html = '<script defer';
        foreach ($attrs as $k => $v) {
            $html .= ' '.$k.'="'.htmlspecialchars((string) $v, ENT_QUOTES).'"';
        }

        return $html.'></script>';
    }
}
