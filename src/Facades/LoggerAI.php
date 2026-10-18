<?php

namespace LoggerAI\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static mixed measure(string $target, string $operation, \Closure $call)
 * @method static void report(\Throwable $e, array $context = [])
 * @method static void flush(bool $force = false)
 * @method static string scriptTag()
 */
final class LoggerAI extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \LoggerAI\Laravel\LoggerAI::class;
    }
}
