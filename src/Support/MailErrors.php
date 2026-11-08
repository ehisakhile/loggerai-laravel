<?php

namespace LoggerAI\Laravel\Support;

use Throwable;

/**
 * Mail errors without the people in them. A mail server's refusal names the
 * recipient ("550 5.1.1 <ada@example.com>: Recipient address rejected"), and
 * Symfony quotes back an address it couldn't parse. What helps debugging stays:
 * the SMTP codes, the server's reason and the host it was talking to.
 */
final class MailErrors
{
    /** Errors from Symfony Mailer and Mime (Laravel 9 and later), and SwiftMailer before it. */
    private const CLASSES = ['Symfony\\Component\\Mailer\\', 'Symfony\\Component\\Mime\\', 'Swift_'];

    /** What a mail error's text looks like, for a log line built from one. */
    private const SIGNS = '/Expected response code|addr-spec|Recipient address|Sender address|mailbox|Connection could not be established|Failed to authenticate|Unable to send|SMTP|smtp/';

    /** The clean message for a mail error; null for anything else. */
    public static function message(Throwable $e): ?string
    {
        for ($at = $e; $at; $at = $at->getPrevious()) {
            foreach (self::CLASSES as $prefix) {
                if (str_starts_with(get_class($at), $prefix)) {
                    return self::clean($e->getMessage());
                }
            }
        }

        return null;
    }

    /** Any text holding a mail error, such as a log line built from $e->getMessage(). */
    public static function text(string $s): string
    {
        return preg_match(self::SIGNS, $s) ? self::clean($s) : $s;
    }

    public static function clean(string $s): string
    {
        // An address Symfony couldn't parse isn't shaped like one: Email "ada at example" does not comply with addr-spec
        $s = preg_replace('/\b(Email|Address|address|mailbox)\s+"[^"]*"/', '$1 "?"', $s) ?? $s;
        $s = preg_replace('/[A-Za-z0-9._%+\'-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '?', $s) ?? $s;

        // Long runs of digits are phone numbers or account ids; SMTP codes (550, 5.1.1) and ports are short.
        return preg_replace('/\+?(?<![\d.])\d[\d\s-]{6,}\d(?![\d.])/', '?', $s) ?? $s;
    }
}
