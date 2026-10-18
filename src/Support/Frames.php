<?php

namespace LoggerAI\Laravel\Support;

use Throwable;

/** Stack frames in the shape LoggerAI groups errors by (docs/INGEST.md). */
final class Frames
{
    public static function from(Throwable $e, string $basePath, int $limit = 40): array
    {
        $base = rtrim($basePath, '/').'/';
        $frames = [];
        // The throw site first, then the call stack.
        $raw = array_merge([['file' => $e->getFile(), 'line' => $e->getLine(), 'function' => null, 'class' => null]], $e->getTrace());
        foreach (array_slice($raw, 0, $limit) as $i => $f) {
            $file = (string) ($f['file'] ?? '');
            if ($file === '') {
                continue;
            }
            $rel = str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
            // The function a frame is in is the caller recorded one frame further down.
            $next = $raw[$i + 1] ?? [];
            $frames[] = [
                'file' => $rel,
                'line' => (int) ($f['line'] ?? 0),
                'function' => (string) ($next['function'] ?? '{main}'),
                'class' => isset($next['class']) ? (string) $next['class'] : null,
                // The app's own code: inside the project, outside vendor/.
                'in_app' => $rel !== $file && ! str_starts_with($rel, 'vendor/'),
            ];
        }

        return $frames;
    }
}
