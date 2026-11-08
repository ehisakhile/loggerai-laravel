# Changelog

## 0.2.1

Database errors are now sent without the data in them.

When a query fails, Laravel writes the values it ran with into the error:

```
SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'ada@example.com'
for key 'users.users_email_unique' (Connection: mysql, SQL: insert into `users`
(`email`, `name`, `phone`) values (ada@example.com, Ada, 08012345678))
```

Up to 0.2.0, that whole message reached LoggerAI. From 0.2.1 it arrives as:

```
SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '?'
for key 'users.users_email_unique' (Connection: mysql, SQL: insert into `users`
(`email`, `name`, `phone`) values (?, ?, ?))
```

The error, its code, the SQL with `?` placeholders and the table, column and key
names stay, so the error is still easy to debug. Values are removed from:

- **Exceptions**: the values the database quotes back (MySQL's `Duplicate entry '…'`,
  PostgreSQL's `Key (email)=(…)` and `Failing row contains (…)`) and any values typed
  straight into the SQL, for example with `whereRaw` or `DB::statement`.
- **Logs**: a line such as `Log::error('Save failed: '.$e->getMessage())`. Laravel writes
  the values into the SQL without quotes, so in logs the SQL stops where the values start
  (`insert into `users` (`email`, `name`) …`).
- **Health checks** from `loggerai:heartbeat`.

The same failure from different users now also groups as one error in LoggerAI instead of
one per value.

### Upgrading

1. Update the package:

   ```bash
   composer update loggerai/laravel
   ```

   `^0.2.0` in your `composer.json` already allows 0.2.1.

2. Deploy. There's nothing to configure: it's on by default, and needs no change to
   `.env`, `config/loggerai.php` or the database.

3. Optional: if you published the config before, you can add the new setting to your copy
   of `config/loggerai.php` so it's visible. Leaving it out has the same effect as `true`.

   ```php
   'redact_query_values' => env('LOGGERAI_REDACT_QUERY_VALUES', true),
   ```

4. Optional: if your app already rewrites `QueryException` messages to keep personal data
   out of logs (for example with `$exceptions->map(QueryException::class, ...)` in
   `bootstrap/app.php`), you can keep it or remove it. Removing it gives you the more
   detailed message above, still without the values.

To check it, cause a duplicate-key error with a test value in a staging app and open the
error in LoggerAI: the value should show as `?`.

To turn it off (only if no personal data ever goes through your database), set
`LOGGERAI_REDACT_QUERY_VALUES=false`.

### Not covered

- Mail errors still include the recipient's email address.
- Errors already sent before you upgrade stay as they are in LoggerAI.
- The values are removed from what LoggerAI receives. Your own log files
  (`storage/logs`) still get Laravel's original message.
