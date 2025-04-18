# BOTLOCK ❌🤖 PHP Bad Bot Blocker

[![Build and Release PHAR](https://github.com/ericges/botlock/actions/workflows/release-phar.yaml/badge.svg)](https://github.com/ericges/botlock/actions/workflows/release-phar.yaml)

BOTLOCK is a PHP script that blocks bad bots and scrapers from accessing your website. It uses a JavaScript challenge which most bots don't support. In the future, it will also check the User Agent to block known bad bots. The script is designed to be easy to use and configure.

## Installation

### Using the PHAR file (recommended)

Download the latest version of the PHAR file from the [releases page](https://github.com/ericges/botlock/releases/latest).

Place the `botlock.phar` file in the root directory of your website (or any other directory that can be accessed by your web server).

In any case, you must enable the script by setting the `BOTLOCK_ENABLED` environment variable to `yes`/`1`/`true`/`on` in your web server configuration.
This way, you can easily opt in or out of using BOTLOCK depending on the traffic your website receives.

> [!NOTE]
> Make sure to replace `/path/to/botlock.phar` with the actual path to the `botlock.phar` file on your server.
> The path should be absolute and accessible by the web server user.
> Make sure to set the correct permissions for the file so that it can be executed by the web server.

#### Using the PHAR file with Apache
In your website's `.htaccess` file, add the following lines to prepend the script to all requests:

```apache
SetEnv BOTLOCK_ENABLED yes
php_value auto_prepend_file "/path/to/botlock.phar"
```

#### Using the PHAR file with Nginx
In your Nginx configuration file, add the following lines to prepend the script to all requests:

```nginx
location / {
    # Other configurations for fastcgi...

    # Prepend the script to all requests
    fastcgi_param BOTLOCK_ENABLED on;
    fastcgi_param PHP_VALUE "auto_prepend_file=/path/to/botlock.phar";
}
```

#### Prepending the script in the php.ini file
If you want to prepend the script to all requests without modifying your web server configuration, you can do so by adding the following line to your `php.ini` file:

```ini
auto_prepend_file = "/path/to/botlock.phar"
```

> [!NOTE]
> Make sure to set the `BOTLOCK_ENABLED` environment variable in your web server configuration as well, as the script will not run if this variable is not set.

If you are using a custom PHP-FPM pool, you can also set the `auto_prepend_file` directive in the pool configuration file (i.e. `/etc/php-fpm.d/www.conf`):

```ini
php_admin_value[auto_prepend_file] = "/path/to/botlock.phar"
```

> [!NOTE]
> Also, ensure that the `auto_prepend_file` directive is not overridden in your web server configuration or in any other `.htaccess` or `php.ini` files.
> In a shared hosting environment, you may not have access to the `php.ini` file.

### Using Composer

If you prefer to use Composer, you can install BOTLOCK as a dependency in your project. Run the following command:

```bash
composer require ericges/botlock
```

Then, at the beginning of your PHP script, after requiring Composer's autoload file, add the following line:

```php
\GES\Botlock\Kernel::boot()->handleRequest();
```

> [!NOTE]
> Not prepending the script to all requests will prevent the script from blocking bad bots accessing static files (e.g. images, CSS, JS).
> Additionally, since all your Composer dependencies are loaded before the script is executed, it may slow down your website as even requests that are blocked will require more resources to process.