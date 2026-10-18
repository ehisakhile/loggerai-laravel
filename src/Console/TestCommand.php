<?php

namespace LoggerAI\Laravel\Console;

use Illuminate\Console\Command;
use LoggerAI\Laravel\LoggerAI;
use RuntimeException;

final class TestCommand extends Command
{
    protected $signature = 'loggerai:test';

    protected $description = 'Send a test request, error and log line to LoggerAI and report whether it arrived.';

    public function handle(LoggerAI $loggerai): int
    {
        if (! config('loggerai.key')) {
            $this->error('  LOGGERAI_KEY isn\'t set. Run php artisan loggerai:install.');

            return self::FAILURE;
        }
        $recorder = $loggerai->recorder();
        $recorder->start('http', 'GET');
        $recorder->dependency('loggerai-test', 'PING', 12.5);
        $recorder->finish(200, '/loggerai/test');
        $recorder->exception(new RuntimeException('LoggerAI test error: everything is wired up. You can resolve this one.'), 500);
        $recorder->log('WARNING', 'LoggerAI test log line');
        $ok = $loggerai->transport()->post($recorder->drain());
        if ($ok) {
            $this->info('  ✓ Connected. A test request, error and log line are on their way to your project.');
            $this->line('    Transport: '.$loggerai->transport()->mode());

            return self::SUCCESS;
        }
        $this->error('  ✗ LoggerAI didn\'t accept the test: '.($loggerai->transport()->lastError ?? 'unknown error'));
        $this->line('    Check LOGGERAI_KEY, and that this server can reach '.config('loggerai.endpoint'));

        return self::FAILURE;
    }
}
