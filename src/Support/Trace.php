<?php

namespace LoggerAI\Laravel\Support;

/**
 * W3C trace context across services: reading traceparent and the caller's
 * name, and deciding which outgoing calls carry them on. By default only the
 * project's own services do (names without a dot, private addresses, internal
 * DNS suffixes), so outside APIs never get your trace ids or service names.
 */
final class Trace
{
    /** [trace id, parent span id] from a traceparent header, or null. */
    public static function parent($header): ?array
    {
        $h = strtolower(trim(is_array($header) ? (string) ($header[0] ?? '') : (string) $header));
        if (! preg_match('/^[0-9a-f]{2}-([0-9a-f]{32})-([0-9a-f]{16})-[0-9a-f]{2}$/', $h, $m)) {
            return null;
        }
        if ($m[1] === str_repeat('0', 32) || $m[2] === str_repeat('0', 16)) {
            return null;
        }

        return [$m[1], $m[2]];
    }

    /** The calling service's name (x-loggerai-caller), if it looks like one. */
    public static function caller($value): ?string
    {
        $v = substr(trim(is_array($value) ? (string) ($value[0] ?? '') : (string) $value), 0, 60);

        return $v !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/ -]{0,59}$/', $v) ? $v : null;
    }

    public static function spanId(): string
    {
        return bin2hex(random_bytes(8));
    }

    public static function isInternal(?string $host): bool
    {
        $h = strtolower(trim((string) $host, '[] '));
        if ($h === '') {
            return false;
        }
        if ($h === 'localhost' || $h === '::1' || preg_match('/^(10\.|127\.|192\.168\.|169\.254\.|172\.(1[6-9]|2\d|3[01])\.)/', $h) || preg_match('/^f[cd][0-9a-f]{2}:/', $h)) {
            return true;
        }
        if (preg_match('/^\d+\.\d+\.\d+\.\d+$/', $h)) {
            return false;
        }

        return ! str_contains($h, '.') || (bool) preg_match('/\.(local|internal|svc|cluster\.local|lan|home\.arpa|consul|localdomain)$/', $h);
    }

    /** setting: null (internal hosts), "all", "none", or hosts and patterns ("api.acme.io,*.acme.io"). */
    public static function propagates(?string $host, $setting): bool
    {
        if ($setting === true || $setting === false) {
            return $setting;
        }
        $s = strtolower(trim((string) $setting));
        if ($s === 'all' || $s === 'true') {
            return true;
        }
        if ($s === 'none' || $s === 'false') {
            return false;
        }
        if (self::isInternal($host)) {
            return true;
        }
        $h = strtolower((string) $host);
        foreach (array_filter(array_map('trim', explode(',', $s))) as $p) {
            if (str_starts_with($p, '*.') ? str_ends_with($h, substr($p, 1)) : $h === $p) {
                return true;
            }
        }

        return false;
    }
}
