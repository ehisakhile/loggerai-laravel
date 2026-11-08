<?php

namespace LoggerAI\Laravel\Support;

use Illuminate\Database\QueryException;
use Throwable;

/**
 * Database errors without the data in them.
 *
 * Laravel writes a failed query's values into the QueryException message
 * ("insert into users (email) values (ada@example.com, Ada)"), and databases
 * quote values back in their own errors ("Duplicate entry 'ada@example.com'",
 * "Key (email)=(ada@example.com) already exists"). What helps debugging stays:
 * the error, the SQL with ? placeholders, and table, column and key names.
 */
final class QueryErrors
{
    /** A quoted name after one of these words is part of the schema, not data. */
    private const NAMES = 'key|column|columns|table|constraint|index|relation|database|schema|field|function|type|sequence|view|trigger|procedure|collation|savepoint|role|user|extension|operator';

    /** MySQL's own words for where a name appeared: Unknown column 'x' in 'field list'. */
    private const PHRASES = '/^(?:field list|(?:where|order|having|on|group|from) clause|group statement)$/i';

    /** The clean message for a QueryException; null for anything else. */
    public static function message(Throwable $e): ?string
    {
        if (! $e instanceof QueryException) {  // instanceof needs no autoload, so apps without illuminate/database are fine
            return null;
        }
        $driver = $e->getPrevious()?->getMessage() ?? self::withoutSql($e->getMessage());
        $connection = method_exists($e, 'getConnectionName') ? $e->getConnectionName() : null;

        return self::driver($driver).' ('.($connection ? "Connection: {$connection}, " : '').'SQL: '.self::sql($e->getSql()).')';
    }

    /**
     * Any text holding a database error, such as a log line built from
     * $e->getMessage(). The values Laravel wrote into the SQL can't be told
     * apart from the SQL any more, so the SQL stops before them.
     */
    public static function text(string $s): string
    {
        if (! str_contains($s, 'SQLSTATE[')) {
            return $s;
        }
        // Laravel 10 and 11: (Connection: mysql, SQL: ...); 12 adds Host, Port and Database before SQL.
        if (preg_match('/^(.*?)\s*\(((?:Connection: [^()]*?, )?)SQL: (.*)\)(.*)$/s', $s, $m)) {
            return self::driver($m[1]).' ('.$m[2].'SQL: '.self::shape($m[3]).')'.self::driver($m[4]);
        }

        return self::driver($s);
    }

    /** The database's own message, with the values it quoted taken out. */
    public static function driver(string $s): string
    {
        // PostgreSQL: Key (email)=(ada@example.com) already exists; Failing row contains (1, ada@..., ...)
        $s = preg_replace('/\b(Key \([^)]*\))=\(.*?\)(?=\s+(?:already exists|is not present|conflicts)|\.|$)/m', '$1=(?)', $s) ?? $s;
        $s = preg_replace('/\b(Failing row contains) \(.*\)/', '$1 (?)', $s) ?? $s;
        // Quoted text is data unless it names part of the schema: Duplicate entry '?' for key 'users_email_unique'
        $s = preg_replace_callback("/(['\"])((?:(?!\\1).)*)\\1/s", function (array $m) use ($s): string {
            [$quoted, $at] = $m[0];
            $before = substr($s, max(0, $at - 40), min(40, $at));

            $named = preg_match('/\b(?:'.self::NAMES.')\s*$/i', $before) || preg_match(self::PHRASES, $m[2][0]);

            return $named ? $quoted : $m[1][0].'?'.$m[1][0];
        }, $s, -1, $count, PREG_OFFSET_CAPTURE) ?? $s;

        return self::emails($s);
    }

    /** SQL as written, with any values typed straight into it (whereRaw, DB::statement) replaced. */
    public static function sql(string $sql): string
    {
        $sql = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", '?', $sql) ?? $sql;

        return self::emails(preg_replace('/(?<![\w.$])\d{5,}(?![\w.])/', '?', $sql) ?? $sql);
    }

    /** SQL that already has the values in it, cut where they start. */
    private static function shape(string $sql): string
    {
        $cut = preg_split('/\s(?:values|where|set|having|limit|offset|on duplicate|returning)\s/i', $sql, 2);
        $head = self::sql(trim($cut[0]));

        return count($cut) > 1 ? $head.' …' : $head;
    }

    private static function withoutSql(string $message): string
    {
        return preg_replace('/\s*\((?:Connection: [^()]*?, )?SQL: .*\)\s*$/s', '', $message) ?? $message;
    }

    private static function emails(string $s): string
    {
        return preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '?', $s) ?? $s;
    }
}
