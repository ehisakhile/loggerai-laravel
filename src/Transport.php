<?php

namespace LoggerAI\Laravel;

use LoggerAI\Laravel\Jobs\SendBatch;

/**
 * Gets records to LoggerAI without making anyone wait:
 *   buffer - append to storage/loggerai/spool.ndjson; whichever request
 *            finds it due (every few seconds) sends it, after its response
 *   sync   - send at the end of each request (serverless)
 *   queue  - dispatch a job per batch to the app's queue
 * Sending uses raw cURL with a short timeout, so it's never timed by the
 * package itself and never throws into the app.
 */
final class Transport
{
    private const MAX_SPOOL = 5 * 1024 * 1024;

    private const MAX_BATCH_BYTES = 900_000;

    public bool $sending = false;  // set while sending, so our own call isn't recorded

    public ?string $lastError = null;

    public function __construct(private array $config, private string $dir)
    {
    }

    public function mode(): string
    {
        $mode = $this->config['transport'] ?? 'auto';
        if ($mode === 'auto') {
            return (getenv('VAPOR_SSM_PATH') || getenv('VAPOR_ARTIFACT_NAME') || isset($_ENV['VAPOR_SSM_PATH'])) ? 'sync' : 'buffer';
        }

        return in_array($mode, ['buffer', 'sync', 'queue'], true) ? $mode : 'buffer';
    }

    public function send(array $parts): void
    {
        if (! $parts) {
            return;
        }
        switch ($this->mode()) {
            case 'sync':
                $this->post($parts);
                break;
            case 'queue':
                try {
                    $job = new SendBatch($parts);
                    if (! empty($this->config['queue'])) {
                        $job->onQueue($this->config['queue']);
                    }
                    dispatch($job);
                } catch (\Throwable $e) {
                    $this->append($parts);  // no queue available: fall back to the buffer
                }
                break;
            default:
                $this->append($parts);
                $this->flushIfDue();
        }
    }

    // ---- buffer --------------------------------------------------------------

    private function spool(): string
    {
        return $this->dir.'/spool.ndjson';
    }

    private function ensureDir(): bool
    {
        return is_dir($this->dir) || @mkdir($this->dir, 0775, true) || is_dir($this->dir);
    }

    public function append(array $parts): void
    {
        if (! $this->ensureDir()) {
            $this->post($parts);  // read-only disk: send straight away

            return;
        }
        if (@filesize($this->spool()) > self::MAX_SPOOL) {
            return;  // LoggerAI unreachable for a while: drop rather than fill the disk
        }
        $lines = '';
        foreach ($parts as $type => $records) {
            foreach ($records as $r) {
                $lines .= json_encode(['t' => $type, 'd' => $r], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)."\n";
            }
        }
        @file_put_contents($this->spool(), $lines, FILE_APPEND | LOCK_EX);
    }

    public function flushIfDue(bool $force = false): int
    {
        $spool = $this->spool();
        clearstatcache(true, $spool);
        if (! is_file($spool) || ! filesize($spool)) {
            return 0;
        }
        $marker = $this->dir.'/last-sent';
        $age = time() - (int) @filemtime($marker);
        if (! $force && $age < max(1, (int) ($this->config['buffer_seconds'] ?? 10)) && filesize($spool) < 512 * 1024) {
            return 0;
        }
        // One sender at a time; others carry on.
        $lock = @fopen($this->dir.'/send.lock', 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            return 0;
        }
        try {
            $taking = $this->dir.'/sending-'.getmypid().'-'.bin2hex(random_bytes(3)).'.ndjson';
            if (! @rename($spool, $taking)) {
                return 0;
            }
            @touch($marker);
            $sent = $this->sendFile($taking);
            @unlink($taking);

            return $sent;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function sendFile(string $file): int
    {
        $parts = [];
        $bytes = 0;
        $sent = 0;
        $h = @fopen($file, 'r');
        if (! $h) {
            return 0;
        }
        while (($line = fgets($h)) !== false) {
            $row = json_decode($line, true);
            if (! is_array($row) || ! isset($row['t'], $row['d'])) {
                continue;
            }
            $parts[$row['t']][] = $row['d'];
            $bytes += strlen($line);
            if ($bytes > self::MAX_BATCH_BYTES || count($parts[$row['t']]) >= 1000) {
                $sent += $this->post($parts) ? 1 : 0;
                $parts = [];
                $bytes = 0;
            }
        }
        fclose($h);
        if ($parts) {
            $sent += $this->post($parts) ? 1 : 0;
        }

        return $sent;
    }

    // ---- sending ---------------------------------------------------------------

    public function batch(array $parts): array
    {
        return array_merge([
            'sdk' => ['name' => 'loggerai-laravel', 'version' => LoggerAI::VERSION],
            'service' => (string) ($this->config['service'] ?? 'app'),
            'environment' => (string) ($this->config['environment'] ?? 'production'),
            'release' => $this->config['release'] ?: null,
            'host' => gethostname() ?: null,
        ], $parts);
    }

    /** POST one batch. True when LoggerAI accepted it. */
    public function post(array $parts): bool
    {
        $key = $this->config['key'] ?? null;
        if (! $key) {
            $this->lastError = 'LOGGERAI_KEY isn\'t set';

            return false;
        }
        if (! function_exists('curl_init')) {
            $this->lastError = 'PHP\'s curl extension is missing';

            return false;
        }
        $body = json_encode($this->batch($parts), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $headers = ['Content-Type: application/json', 'X-LoggerAI-Key: '.$key, 'User-Agent: loggerai-laravel/'.LoggerAI::VERSION];
        if (function_exists('gzencode') && strlen($body) > 1024) {
            $body = gzencode($body, 6);
            $headers[] = 'Content-Encoding: gzip';
        }
        $this->sending = true;
        try {
            $ch = curl_init($this->config['endpoint']);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => 1000,
                CURLOPT_TIMEOUT_MS => (int) (1000 * ($this->config['timeout'] ?? 2.0)),
                CURLOPT_NOSIGNAL => true,
            ]);
            $out = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            $this->lastError = $code >= 200 && $code < 300 ? null : ($err ?: "HTTP $code: ".substr((string) $out, 0, 200));

            return $this->lastError === null;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return false;
        } finally {
            $this->sending = false;
        }
    }
}
