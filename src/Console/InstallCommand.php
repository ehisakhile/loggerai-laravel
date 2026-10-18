<?php

namespace LoggerAI\Laravel\Console;

use Illuminate\Console\Command;

final class InstallCommand extends Command
{
    protected $signature = 'loggerai:install {--key= : Your project\'s secret key (sk_live_…)} {--public-key= : Its public key (pk_live_…)}';

    protected $description = 'Set up LoggerAI: add your keys to .env and check the connection.';

    public function handle(): int
    {
        $this->line('');
        $this->line('  <fg=green;options=bold>LoggerAI</> for Laravel');
        $this->line('');
        $key = $this->option('key') ?: $this->ask('Secret key (LoggerAI → your project → Connect)');
        if (! $key || ! str_starts_with($key, 'sk_')) {
            $this->error('That doesn\'t look like a secret key. It starts with sk_.');

            return self::FAILURE;
        }
        $public = $this->option('public-key') ?: $this->ask('Public key, to record browser sessions (optional, starts with pk_)', '');

        $env = base_path('.env');
        if (is_writable($env)) {
            $this->setEnv($env, 'LOGGERAI_KEY', $key);
            if ($public) {
                $this->setEnv($env, 'LOGGERAI_PUBLIC_KEY', $public);
            }
            $this->info('  Added your keys to .env.');
        } else {
            $this->warn("  Couldn't write .env. Add these yourself:");
            $this->line("    LOGGERAI_KEY=$key".($public ? "\n    LOGGERAI_PUBLIC_KEY=$public" : ''));
        }
        config(['loggerai.key' => $key, 'loggerai.public_key' => $public ?: null]);
        // Rebuild with the new keys (they were made before .env changed).
        foreach ([\LoggerAI\Laravel\Transport::class, \LoggerAI\Laravel\LoggerAI::class] as $abstract) {
            app()->forgetInstance($abstract);
        }

        $this->line('');
        $this->call('loggerai:test');
        $this->line('');
        $this->line('  Next:');
        $this->line('   • Deploy. Requests, queries, jobs, errors and logs show up within a minute.');
        $this->line('   • Optional: set LOGGERAI_RELEASE to your git SHA or version on each deploy.');
        $this->line("   • Low traffic or shared hosting? Add <comment>\$schedule->command('loggerai:flush')->everyMinute();</comment>");
        $this->line('     so nothing waits for the next request to be sent.');

        return self::SUCCESS;
    }

    private function setEnv(string $path, string $name, string $value): void
    {
        $content = (string) file_get_contents($path);
        $line = $name.'='.$value;
        $content = preg_match("/^{$name}=.*$/m", $content)
            ? preg_replace("/^{$name}=.*$/m", $line, $content)
            : rtrim($content)."\n\n".$line."\n";
        file_put_contents($path, $content);
    }
}
