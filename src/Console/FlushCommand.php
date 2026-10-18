<?php

namespace LoggerAI\Laravel\Console;

use Illuminate\Console\Command;
use LoggerAI\Laravel\LoggerAI;

final class FlushCommand extends Command
{
    protected $signature = 'loggerai:flush';

    protected $description = 'Send anything waiting in the LoggerAI buffer (schedule it every minute on quiet or shared-hosting apps).';

    public function handle(LoggerAI $loggerai): int
    {
        $sent = $loggerai->transport()->flushIfDue(true);
        $this->line($sent ? "  Sent $sent batch(es)." : '  Nothing waiting.');

        return self::SUCCESS;
    }
}
