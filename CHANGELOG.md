# Changelog

## 0.2.2

Mail errors are now sent without the people in them.

A mail server's refusal names the recipient, and Symfony Mailer quotes back the login it used
or an address it couldn't parse:

```
Expected response code "250/251/252" but got code "550", with message
"550 5.1.1 <ada@example.com>: Recipient address rejected: User unknown in virtual mailbox table".
```

From 0.2.2 this arrives as `... "550 5.1.1 <?>: Recipient address rejected: User unknown in
virtual mailbox table".` The SMTP codes, the server's reason and the mail host stay. Addresses
and long numbers are removed from mail exceptions and from log lines built from one.

### Upgrading

Run `composer update loggerai/laravel` and deploy; it's on by default. If your app already
rewrites mail errors (for example `$exceptions->map(TransportExceptionInterface::class, ...)`),
you can keep it or remove it. To turn it off, set `LOGGERAI_REDACT_MAIL_ADDRESSES=false`, or
add `'redact_mail_addresses' => env('LOGGERAI_REDACT_MAIL_ADDRESSES', true),` to a published
`config/loggerai.php` to see the setting.

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

### Also in 0.2.1: locations are never kept

Fields named like a location (`lat`, `lng`, `lon`, `latitude`, `longitude`, `coords`,
`geolocation`, `gps`, and names such as `user_lat`, `userLng`, `pickup_latitude`), the `ll` and
`location` URL parameters, and latitude,longitude pairs anywhere (for example
`/nearby/6.524379,3.379206`) are now masked before anything is sent. If you relied on seeing
coordinates in LoggerAI, they'll show as `[REDACTED]` or `[REDACTED_LOCATION]` from 0.2.1.

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

- Mail errors still include the recipient's email address (fixed in 0.2.2).
- Errors already sent before you upgrade stay as they are in LoggerAI.
- The values are removed from what LoggerAI receives. Your own log files
  (`storage/logs`) still get Laravel's original message.
