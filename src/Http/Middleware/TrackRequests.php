<?php

namespace LoggerAI\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use LoggerAI\Laravel\LoggerAI;
use Symfony\Component\HttpFoundation\Response;

/**
 * Times every HTTP request under its route pattern ("/orders/{order}"),
 * links it to the browser session that made it, adds the session script to
 * HTML pages, and sends after the response has gone to the user.
 */
final class TrackRequests
{
    public function __construct(private LoggerAI $loggerai)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        if (! $this->loggerai->enabled() || ! $this->loggerai->config('capture.requests', true) || $this->ignored($request)) {
            return $next($request);
        }
        // W3C traceparent: 00-<trace id>-<span id>-<flags>
        $trace = null;
        if (preg_match('/^[0-9a-f]{2}-([0-9a-f]{32})-[0-9a-f]{16}-[0-9a-f]{2}$/', (string) $request->header('traceparent'), $m)) {
            $trace = $m[1];
        }
        $session = $request->header('x-loggerai-session');
        $session = is_string($session) && preg_match('/^[0-9a-fA-F-]{8,64}$/', $session) ? $session : null;
        $started = (float) $request->server('REQUEST_TIME_FLOAT', 0);
        $started = $started > 0 && microtime(true) - $started < 120 ? $started : null;  // Octane: per-worker, not per-request
        $this->loggerai->recorder()->start('http', $request->getMethod(), $trace, $session, $started);

        $response = $next($request);

        if ($this->loggerai->config('sessions.inject', true)) {
            $this->inject($request, $response);
        }

        return $response;
    }

    public function terminate(Request $request, $response): void
    {
        $recorder = $this->loggerai->recorder();
        if (! $recorder->active()) {
            return;
        }
        $route = $request->route();
        $uri = is_object($route) && method_exists($route, 'uri') ? '/'.ltrim($route->uri(), '/') : '(unmatched)';
        $status = $response instanceof Response ? $response->getStatusCode() : 200;
        $recorder->finish($status, $uri, $this->loggerai->userRef());
        $this->loggerai->flush();
    }

    private function ignored(Request $request): bool
    {
        foreach ((array) $this->loggerai->config('ignore.paths', []) as $pattern) {
            if ($request->is($pattern)) {
                return true;
            }
        }

        return false;
    }

    private function inject(Request $request, $response): void
    {
        if (! $response instanceof Response || $response->getStatusCode() >= 300 || $request->ajax() || $request->expectsJson()) {
            return;
        }
        if (! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return;
        }
        $content = $response->getContent();
        if (! is_string($content) || $content === '' || str_contains($content, 'src="/loggerai.js')) {
            return;
        }
        $tag = $this->loggerai->scriptTag();
        $pos = stripos($content, '</head>');
        if ($tag === '' || $pos === false) {
            return;
        }
        $response->setContent(substr($content, 0, $pos).$tag.substr($content, $pos));
    }
}
