# Install Secure Dice

## Deploy the application

1. Use PHP 8.1 or later with sessions, Ctype, PDO MySQL, and Sodium. For SMTP over TLS, enable OpenSSL.
2. Copy the entire `securedice/` directory to your HTTPS site, including `.htaccess`. The directory includes the theme and mailer. On Unix, `755` for application directories and `644` for application files are typical. The web server needs to read these files and traverse the directories. It can do both with the application files owned by the deployment account.
3. Set `securedice.php` as the directory index, or open it directly. Apache can use the included `.htaccess`. On other servers, disable directory listings and deny HTTP access to dotfiles and the `bin/` and `vendor/` directories.

## Configure MySQL

Create an empty database and grant the application's database account `CREATE`, `REFERENCES`, `SELECT`, `INSERT`, `UPDATE`, and `DELETE` on it. Secure Dice creates its `sd2_*` tables on first database use. The [schema](../mysql-schema.php) uses InnoDB and `utf8mb4` and is applied automatically.

Create a private configuration file outside the web root. On Unix, set its permissions to `600` and make it readable by the account running PHP. Set `SECUREDICE_CONFIG_PATH` to the file's absolute path in the PHP environment for web requests and any scheduled worker.

```ini
SECUREDICE_DB_HOST="localhost"
SECUREDICE_DB_PORT="3306"
SECUREDICE_DB_NAME="YOUR_DATABASE_NAME"
SECUREDICE_DB_USER="YOUR_DATABASE_USER"
SECUREDICE_DB_PASSWORD="YOUR_DATABASE_PASSWORD"
SECUREDICE_SECRET="YOUR_GENERATED_64_HEX_CHARACTERS"
```

Generate the secret once with `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`. Keep it with your database backups so existing rolls and recipient records remain usable.

## Initialize the schema manually (fallback)

If automatic initialization fails, run the same initializer from the `securedice/` directory. Point it at a private configuration file for the application's database. If the web account lacks schema creation privileges, use a separate private file for an account with the grants listed above.

```sh
SECUREDICE_CONFIG_PATH=/path/to/private-config php -r 'require "storage.php"; initialize_result_storage(securedice_mysql_connection());'
```

Then make a test roll using the regular web configuration.

## Enable email delivery (optional)

Add these settings to the same private file. Use the public HTTPS URL of the application directory and the SMTP settings from your mail provider. Use `starttls` for STARTTLS or `smtps` for implicit TLS.

```ini
SECUREDICE_BASE_URL="https://example.org/securedice"
SECUREDICE_SMTP_HOST="smtp.example.org"
SECUREDICE_SMTP_PORT="587"
SECUREDICE_SMTP_ENCRYPTION="starttls"
SECUREDICE_SMTP_USERNAME="YOUR_SMTP_USER"
SECUREDICE_SMTP_PASSWORD="YOUR_SMTP_PASSWORD"
SECUREDICE_SMTP_FROM_ADDRESS="dice@example.org"
```

Schedule the worker to run every minute. Replace both executable and application paths with your server's paths.

```sh
/usr/bin/php /path/to/securedice/bin/process-email-queue.php --quiet
```

Open `securedice.php`, make a test roll, and follow its verification link. If you enabled email, test recipient opt-in and delivery.
