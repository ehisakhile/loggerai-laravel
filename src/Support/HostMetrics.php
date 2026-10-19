<?php

namespace LoggerAI\Laravel\Support;

use Throwable;

/**
 * The server the app runs on: CPU, memory and disk, so LoggerAI can say
 * "CPU on web-1 hit 97%" when the app slows down. Sampled after a response
 * at most every 30 seconds per server (a state file in storage/loggerai keeps
 * the time and the CPU counters between requests). Inside a container the
 * container's own limits count (cgroup v2). Reads Linux's /proc; elsewhere it
 * sends what PHP can see (load and disk). Never throws.
 */
final class HostMetrics
{
    /** Where /proc and /sys live: '' on a real server, a folder in tests. */
    public function __construct(private string $fs = '')
    {
    }

    /** A sample if one is due on this server, else null. */
    public static function due(string $dir, string $root, int $every = 30, string $fs = ''): ?array
    {
        $state = $dir.'/host.json';
        $mtime = @filemtime($state);
        if ($mtime !== false && time() - $mtime < $every) {
            return null;  // the common case: one stat() per request
        }
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return null;
        }
        $fh = @fopen($state, 'c+');
        if (! $fh) {
            return null;
        }
        try {
            if (! flock($fh, LOCK_EX | LOCK_NB)) {
                return null;  // another worker is sampling right now
            }
            $prev = json_decode((string) stream_get_contents($fh), true);
            $prev = is_array($prev) ? $prev : [];
            if (isset($prev['at']) && microtime(true) - (float) $prev['at'] < $every) {
                return null;
            }
            $next = [];
            $sample = (new self($fs))->sample($prev, $root, $next);
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, (string) json_encode($next));
            fflush($fh);

            return $sample;
        } catch (Throwable) {
            return null;
        } finally {
            @flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /** One reading. $prev is the last call's $next (CPU needs two readings). */
    public function sample(array $prev, string $root, ?array &$next = null): array
    {
        $now = microtime(true);
        $next = ['at' => $now];
        $r = ['ts' => round($now, 3)];

        // CPU: the container's quota when it has one, else the whole machine.
        $limit = $this->cgroupCpuLimit();
        if ($limit !== null) {
            $usec = $this->cgroupCpuUsec();
            $r['cpus'] = round($limit, 2);
            if ($usec !== null) {
                $next['usec'] = $usec;
                if (isset($prev['usec'], $prev['at']) && $now > $prev['at']) {
                    $r['cpu_pct'] = round(min(100, max(0, ($usec - $prev['usec']) / 1e6 / ($now - $prev['at']) / $limit * 100)), 1);
                }
            }
        } else {
            if ($cpus = $this->cpuCount()) {
                $r['cpus'] = $cpus;
            }
            if ($stat = $this->procCpu()) {
                [$next['busy'], $next['total']] = $stat;
                if (isset($prev['busy'], $prev['total']) && $stat[1] > $prev['total']) {
                    $r['cpu_pct'] = round(max(0, ($stat[0] - $prev['busy']) / ($stat[1] - $prev['total']) * 100), 1);
                }
            }
        }

        $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : false;
        if (is_array($load) && isset($load[0])) {
            $r['load1'] = round((float) $load[0], 2);
        }

        // Memory: the container's limit when it has one, else the machine's RAM.
        if ($cg = $this->cgroupMemory()) {
            $r['mem_used_pct'] = round($cg[0] / $cg[1] * 100, 1);
            $r['mem_total_mb'] = round($cg[1]);
        } else {
            $mi = $this->meminfo();
            if (! empty($mi['MemTotal']) && isset($mi['MemAvailable'])) {
                $r['mem_used_pct'] = round((1 - $mi['MemAvailable'] / $mi['MemTotal']) * 100, 1);
                $r['mem_total_mb'] = round($mi['MemTotal']);
            }
            if (! empty($mi['SwapTotal'])) {
                $r['swap_used_pct'] = round((1 - ($mi['SwapFree'] ?? 0) / $mi['SwapTotal']) * 100, 1);
            }
        }

        $total = @disk_total_space($root);
        $free = @disk_free_space($root);
        if ($total && $free !== false) {
            $r['disk_used_pct'] = round(($total - $free) / $total * 100, 1);
        }

        if (preg_match('/^VmRSS:\s+(\d+)/m', (string) $this->read('/proc/self/status'), $m)) {
            $r['process_mem_mb'] = round($m[1] / 1024, 1);
        }

        return $r;
    }

    private function read(string $path): ?string
    {
        $v = @file_get_contents($this->fs.$path);

        return $v === false ? null : $v;
    }

    /** [busy, total] jiffies for the whole machine. */
    private function procCpu(): ?array
    {
        $line = strtok((string) $this->read('/proc/stat'), "\n");
        $v = preg_split('/\s+/', trim((string) $line));
        if (! $v || $v[0] !== 'cpu' || count($v) < 9) {
            return null;
        }
        $n = array_map('floatval', array_slice($v, 1, 8));  // user nice system idle iowait irq softirq steal
        $total = array_sum($n);

        return [$total - $n[3] - $n[4], $total];
    }

    private function cpuCount(): ?int
    {
        $n = preg_match_all('/^processor\s*:/m', (string) $this->read('/proc/cpuinfo'));

        return $n ?: null;
    }

    private function cgroupCpuLimit(): ?float
    {
        $v = preg_split('/\s+/', trim((string) $this->read('/sys/fs/cgroup/cpu.max')));
        if (count($v) === 2 && $v[0] !== 'max' && (float) $v[1] > 0) {
            return (float) $v[0] / (float) $v[1];
        }

        return null;
    }

    private function cgroupCpuUsec(): ?float
    {
        return preg_match('/^usage_usec (\d+)/m', (string) $this->read('/sys/fs/cgroup/cpu.stat'), $m) ? (float) $m[1] : null;
    }

    /** [used, limit] MB for a container with a memory limit. */
    private function cgroupMemory(): ?array
    {
        $limit = trim((string) $this->read('/sys/fs/cgroup/memory.max'));
        $current = trim((string) $this->read('/sys/fs/cgroup/memory.current'));
        if ($limit === '' || $limit === 'max' || ! ctype_digit($current) || ! ctype_digit($limit)) {
            return null;
        }
        $used = (float) $current;
        if (preg_match('/^inactive_file (\d+)/m', (string) $this->read('/sys/fs/cgroup/memory.stat'), $m)) {
            $used -= (float) $m[1];  // page cache the kernel can drop: not really in use
        }

        return [max($used, 0) / 1048576, (float) $limit / 1048576];
    }

    private function meminfo(): array
    {
        $out = [];
        foreach (explode("\n", (string) $this->read('/proc/meminfo')) as $line) {
            if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
                $out[$m[1]] = $m[2] / 1024;  // kB → MB
            }
        }

        return $out;
    }
}
