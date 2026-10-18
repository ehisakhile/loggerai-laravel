<?php

namespace LoggerAI\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use LoggerAI\Laravel\Transport;

/** The queue transport: sends one batch from a queue worker. Never recorded itself. */
final class SendBatch implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 2;

    public int $timeout = 15;

    public function __construct(public array $parts)
    {
    }

    public function handle(Transport $transport): void
    {
        $transport->post($this->parts);  // failures are dropped: monitoring must never pile up work
    }
}
