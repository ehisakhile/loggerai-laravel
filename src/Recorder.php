<?php

namespace LoggerAI\Laravel;

use LoggerAI\Laravel\Support\Frames;
use LoggerAI\Laravel\Support\Redactor;
use Throwable;

/**
 * Holds what happened during the current unit of work (an HTTP request, a
 * queue job or a command) until it ends, then hands the record to the
 * transport. One instance per process; reset at each start (Octane-safe).
 */
final class Recorder
{
    private ?array $current = null;

    private array $requests = [];

    private array $errors = [];

    private array $logs = [];

    private array $checks = [];

    public function __construct(private Redactor $redactor, private string $basePath)
    {
    }

    public function start(string $kind, string $method, ?string $traceId = null, ?string $sessionId = null, ?float $startedAt = null): void
    {
        $now = microtime(true);
        $this->current = [
            'ts' => $startedAt ?? $now,
            'kind' => $kind,
            'method' => $method,
            'route' => null,
            'trace_id' => $traceId ?: bin2hex(random_bytes(16)),
            'session_id' => $sessionId,
            'deps' => [],
            'started' => $startedAt ?? $now,
            'error_fingerprint' => null,
        ];
    }

    public function active(): bool
    {
        return $this->current !== null;
    }

    public function traceId(): ?string
    {
        return $this->current['trace_id'] ?? null;
    }

    public function route(): ?string
    {
        return $this->current['route'] ?? null;
    }

    public function setRoute(string $route): void
    {
        if ($this->current) {
            $this->current['route'] = $route;
        }
    }

    /** A call to a database, cache or outside service, grouped by target and operation. */
    public function dependency(string $target, string $operation, float $ms, bool $ok = true): void
    {
        if (! $this->current) {
            return;  // outside any request/job (e.g. booting): not worth a record
        }
        $key = $target.'|'.$operation;
        $d = &$this->current['deps'][$key];
        $d ??= ['target' => $target, 'op' => $operation, 'count' => 0, 'ms' => 0.0, 'errors' => 0];
        $d['count']++;
        $d['ms'] += $ms;
        if (! $ok) {
            $d['errors']++;
        }
    }

    public function finish(int $status, ?string $route = null, ?string $user = null): void
    {
        if (! $this->current) {
            return;
        }
        $c = $this->current;
        $this->current = null;
        $this->requests[] = [
            'ts' => round($c['ts'], 3),
            'kind' => $c['kind'],
            'method' => $c['method'],
            'route' => $route ?? $c['route'] ?? '(unmatched)',
            'status' => $status,
            'duration_ms' => round((microtime(true) - $c['started']) * 1000, 2),
            'trace_id' => $c['trace_id'],
            'session_id' => $c['session_id'],
            'user' => $user,
            'deps' => array_values(array_map(fn ($d) => ['target' => $d['target'], 'op' => $d['op'], 'count' => $d['count'], 'ms' => round($d['ms'], 2), 'errors' => $d['errors']], $c['deps'])),
        ];
    }

    public function exception(Throwable $e, ?int $status = null, array $context = []): void
    {
        $this->errors[] = [
            'ts' => round(microtime(true), 3),
            'type' => get_class($e),
            'message' => $this->redactor->string(mb_substr($e->getMessage(), 0, 1000)),
            'handled' => false,
            'route' => $this->current['route'] ?? null,
            'method' => $this->current['method'] ?? null,
            'status' => $status,
            'trace_id' => $this->current['trace_id'] ?? null,
            'frames' => Frames::from($e, $this->basePath),
            'context' => $context ? $this->redactor->value($context) : (object) [],
        ];
    }

    public function log(string $level, string $message): void
    {
        $this->logs[] = [
            'ts' => round(microtime(true), 3),
            'level' => strtoupper($level),
            'logger' => 'laravel',
            'message' => $this->redactor->string(mb_substr($message, 0, 8000)),
            'route' => $this->current['route'] ?? null,
            'trace_id' => $this->current['trace_id'] ?? null,
        ];
    }

    public function check(string $name, bool $ok, float $ms, ?string $detail = null): void
    {
        $this->checks[] = ['ts' => round(microtime(true), 3), 'name' => $name, 'ok' => $ok, 'ms' => round($ms, 2), 'detail' => $detail];
    }

    public function pending(): int
    {
        return count($this->requests) + count($this->errors) + count($this->logs) + count($this->checks);
    }

    /** Everything recorded so far, emptied. */
    public function drain(): array
    {
        $out = array_filter([
            'requests' => $this->requests, 'errors' => $this->errors, 'logs' => $this->logs, 'checks' => $this->checks,
        ]);
        $this->requests = $this->errors = $this->logs = $this->checks = [];

        return $out;
    }
}
