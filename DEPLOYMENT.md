# Secure Dice Deployment Guide

This guide describes a production installation on DreamHost Shared Hosting. The same layout works on another Unix host when its PHP and cron paths are substituted.

## Before deployment

You need:

- A DreamHost Shell user assigned to `rpglibrary.org`.
- PHP 8.1 or newer with PDO MySQL and Sodium.
- A fully hosted DreamHost mailbox for SMTP authentication, such as `webmaster@rpglibrary.org`.
- The current `SecureDice-<version>.zip` from GitHub Releases.
- SSH or DreamHost File Manager access.

Release ZIPs include PHPMailer and its production autoloader. A deployment from a Git source checkout must run `composer install --no-dev --optimize-autoloader` before use.

## 1. Prepare MySQL

Use the existing `rpglibrary_org` database on `db.rpglibrary.org`. Secure Dice 2 creates only tables whose names begin with `sd2_`. The MySQL account needs CREATE, ALTER, DROP, SELECT, INSERT, UPDATE, and DELETE privileges to create tables, copy rolls, and rename the legacy tables. Back up the existing database before running the one-time migration. DreamHost Shared MySQL does not allow application-created triggers; Secure Dice detects direct changes to stored results with their HMAC on read.

## 2. Install the release

Extract the release beneath the site's public `software` directory so that the application entry point is:

```text
/home/YOUR_DREAMHOST_USER/rpglibrary.org/software/securedice/securedice.php
```

Do not upload a completed `.securedice.env` into this directory. The distributed `.securedice.env.example` contains placeholders only.

## 3. Create the private configuration

From the installed application directory:

```shell
cp .securedice.env.example /home/YOUR_DREAMHOST_USER/.securedice.env
chmod 600 /home/YOUR_DREAMHOST_USER/.securedice.env
```

Generate the application secret once:

```shell
/usr/local/php84/bin/php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Edit `/home/YOUR_DREAMHOST_USER/.securedice.env` and replace every placeholder. A DreamHost configuration should resemble:

```text
SECUREDICE_DB_HOST="db.rpglibrary.org"
SECUREDICE_DB_PORT="3306"
SECUREDICE_DB_NAME="rpglibrary_org"
SECUREDICE_DB_USER="REPLACE_WITH_MYSQL_USER"
SECUREDICE_DB_PASSWORD="REPLACE_WITH_MYSQL_PASSWORD"
SECUREDICE_SECRET="REPLACE_WITH_THE_GENERATED_64_HEXADECIMAL_CHARACTERS"
SECUREDICE_BASE_URL="https://www.rpglibrary.org/software/securedice"

SECUREDICE_SMTP_HOST="smtp.dreamhost.com"
SECUREDICE_SMTP_PORT="587"
SECUREDICE_SMTP_ENCRYPTION="starttls"
SECUREDICE_SMTP_USERNAME="webmaster@rpglibrary.org"
SECUREDICE_SMTP_PASSWORD="REPLACE_WITH_THE_MAILBOX_PASSWORD"
SECUREDICE_SMTP_FROM_ADDRESS="securedice@rpglibrary.org"
SECUREDICE_SMTP_FROM_NAME="Secure Dice"
SECUREDICE_SMTP_TIMEOUT="15"
```

Never regenerate `SECUREDICE_SECRET` for an existing database. It encrypts recipient addresses and keys private fingerprints. Back up the configuration together with the database.

Secure Dice reads `$HOME/.securedice.env` automatically in both web and command-line PHP. Existing process environment variables override file values. `SECUREDICE_CONFIG_PATH` may select another file, but it must be set consistently for web requests and cron.

## 4. Configure DreamHost email

Use a fully hosted mailbox such as `webmaster@rpglibrary.org` for SMTP authentication. The From address may be the separate `securedice@rpglibrary.org` forward-only address so replies reach its configured destination. Verify both sending and a reply before launch.

DreamHost recommends authenticated SMTP through `smtp.dreamhost.com` on port 587 with STARTTLS. Secure Dice does not use PHP `mail()`.

If the domain's DNS is hosted somewhere other than DreamHost, including Cloudflare, reproduce DreamHost's required mail DNS records at that DNS provider. Confirm SPF and DKIM before testing delivery. Add a DMARC policy appropriate for the domain after legitimate mail passes SPF and DKIM alignment.

## 5. Import legacy rolls once

Stop Secure Dice 1 writes before importing. Its `secure_dice` table is MyISAM and approximately 1.4 GiB, so allow adequate database free space and time for the copy and complete row-by-row comparison. Back up the **whole** `rpglibrary_org` database first, including the old tables. Keep the newly configured `SECUREDICE_SECRET` safe with future backups.

Run the CLI migration from the deployed application directory, using the same Shell user and private configuration as the website:

```shell
/usr/local/php84/bin/php bin/migrate-legacy-mysql.php --execute
```

The script imports only `secure_dice` rolls into `sd2_legacy_rolls`, preserving `id`, `dice_rolled`, `hash`, and `results` exactly. Legacy hashes remain legacy data; they do not become authenticated Secure Dice 2 records. Old sender and recipient addresses are **not** imported. It compares each copied row and checks row counts and the highest ID before renaming the original `secure_dice` and `rpglibrary_securedice_*` tables with `sd1_` prefixes. It records completion in `sd2_schema_meta` and skips copying on subsequent runs. If interrupted before verification, it checks existing copies and resumes. If verification fails, do not launch the new site; inspect the reported mismatch while keeping the old data untouched.

A deployment cannot be considered migrated until this command reports the verified row count. The old tables remain in MySQL under their `sd1_` names for later inspection. Do not run Secure Dice 1 after the rename.

## 6. Initialize and test the application

Open:

```text
https://www.rpglibrary.org/software/securedice/securedice.php
```

Perform these checks in order:

1. Roll ordinary dice and confirm the result page appears.
2. Open the verification link and confirm the immutable record is retrieved.
3. Open **Email opt-in and settings** and submit the dedicated test recipient.
4. Run the queue worker manually without `--quiet`:

   ```shell
   /usr/local/php84/bin/php /home/YOUR_DREAMHOST_USER/rpglibrary.org/software/securedice/bin/process-email-queue.php --limit=20
   ```

5. Follow the confirmation email once and save the private settings link.
6. Roll again, send the result to the opted-in address, wait at least 15 seconds, and run the worker again.
7. Confirm the result email contains readable arithmetic, a working verification link, aggregate recipient counts, and a working unsubscribe confirmation page.
8. Test **Paused**, resume with **Tabletop session**, and verify that **Revoke Consent** stops pending and future result email.

Do not use production recipient addresses until this sequence succeeds.

## 7. Schedule the queue worker

In DreamHost's **Cron Jobs** panel, choose the same Shell user that owns the site, enable locking, select a one-minute custom schedule, and run:

```text
/usr/local/php84/bin/php /home/YOUR_DREAMHOST_USER/rpglibrary.org/software/securedice/bin/process-email-queue.php --limit=100 --quiet
```

`--quiet` suppresses the routine success line so DreamHost does not email cron output every minute. Failures still go to standard error and the PHP error log. Omit `--quiet` during initial testing.

The worker uses database leases, so an abandoned run is recovered. DreamHost locking adds another layer of protection against overlapping scheduled executions.

## 8. Logs and delivery history

Operational failures are written to the PHP error log with generic categories rather than addresses, passwords, SMTP response text, or private tokens. DreamHost's panel and Shell-user logs should be checked after the first scheduled runs.

Sanitized queue state and delivery history are retained in MySQL. Encrypted queue payloads are purged after successful delivery, permanent failure, supersession, or recipient revocation.

## Backups

Back up the private `.securedice.env` and the full MySQL database together. Use DreamHost's MySQL backup facility or `mysqldump` with the existing database credentials; keep the dump outside the public website and store an encrypted off-host copy. For the first migration, verify that the backup contains the approximately 426,375-row `secure_dice` table and all `rpglibrary_securedice_*` tables before running the importer. A backup is proven only when a test restore can read an old result and verify a Secure Dice 2 result with the matching secret.

## Upgrading

1. Download the new versioned release ZIP.
2. Read its release notes.
3. Make and verify a database/configuration backup.
4. Extract the release into a new sibling directory, not over the running installation.
5. Confirm `.securedice.env` remains outside both application directories.
6. Rename the old directory to `securedice-previous` and the new directory to `securedice`.
7. Open a stored verification link, roll once, and run the worker manually.
8. Retain the previous application directory and pre-upgrade database backup until normal operation is confirmed.

The `sd2_` schema initializes when the application first opens storage. Legacy import is an explicit, one-time CLI operation. MySQL DDL is not transactional, so always keep a tested backup before changing the schema.

## Rollback

If the new release fails before a schema change, restore the previous application directory.

If the schema changed, restore the previous application directory and its matching MySQL backup together. Secure Dice 1 cannot run after its old tables have been renamed unless those names are restored. Preserve the failed database separately for diagnosis.

The private configuration normally remains compatible across upgrades. Restore its matching backup if configuration keys or the application secret were changed.

## Lost-secret recovery

If `SECUREDICE_SECRET` is lost or changed accidentally:

1. Stop the queue cron job.
2. Restore `.securedice.env` and the MySQL database from the same known-good backup set.
3. Run the worker manually and test one existing private settings link.

Existing encrypted recipient addresses cannot be recovered without the original secret, and stored dice results cannot pass their server-authentication check. Do not silently generate a replacement for an existing database. The same secret authenticates results and protects consent records and keyed rate-limit identifiers.

## Release acceptance checklist

- [ ] Application and verification URLs use HTTPS.
- [ ] `.securedice.env` is outside the web directory and mode `600`.
- [ ] MySQL settings point to `db.rpglibrary.org`, `rpglibrary_org`, and an account with the required `sd2_` schema privileges.
- [ ] Legacy migration reports a fully verified row count and the `sd1_` tables remain available.
- [ ] `SECUREDICE_SECRET` and the database are backed up together.
- [ ] DreamHost SMTP authentication succeeds over STARTTLS.
- [ ] SPF and DKIM pass; DMARC alignment is checked.
- [ ] One-minute cron runs under the correct Shell user with locking and `--quiet`.
- [ ] Opt-in, settings recovery, pause, resume, revocation, and unsubscribe work.
- [ ] Result delivery, aggregate counts, verification links, batching, and retry behavior work.
- [ ] Desktop keyboard navigation and narrow-screen layout have been checked.
