<?php

namespace LoggerAI\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LoggerAI\Laravel\LoggerAI;
use Throwable;

/** Health checks for the database and cache, and the server's CPU and memory: schedule it every minute
 * to get uptime and "check failing" alerts, and server metrics even when no one is visiting. */
final class HeartbeatCommand extends Command
{
    protected $signature = 'loggerai:heartbeat';

    protected $description = 'Check the database and cache and report it to LoggerAI.';

    public function handle(LoggerAI $loggerai): int
    {
        $r = $loggerai->recorder();
        foreach (['database' => fn () => DB::select('SELECT 1'), 'cache' => fn () => Cache::put('loggerai:heartbeat', 1, 60)] as $name => $check) {
            $t = microtime(true);
            try {
                $check();
                $r->check($name, true, (microtime(true) - $t) * 1000);
            } catch (Throwable $e) {
                $r->check($name, false, (microtime(true) - $t) * 1000, substr($e->getMessage(), 0, 200));
            }
        }
        $loggerai->sampleHost();  // a quiet site still reports its server every minute
        $loggerai->transport()->post($r->drain());

        return self::SUCCESS;
    }
}
