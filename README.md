# BOTLOCK ❌🤖 PHP Bad Bot Blocker

[![CI](https://github.com/ericges/botlock/actions/workflows/ci.yaml/badge.svg)](https://github.com/ericges/botlock/actions/workflows/ci.yaml)
[![Build and Release PHAR](https://github.com/ericges/botlock/actions/workflows/release-phar.yaml/badge.svg)](https://github.com/ericges/botlock/actions/workflows/release-phar.yaml)

BOTLOCK is a PHP script that blocks bad bots and scrapers from accessing your website. It uses a JavaScript challenge which most bots don't support. Crawlers are recognized by their User-Agent (via [CrawlerDetect](https://github.com/JayBizzle/Crawler-Detect)): known good bots such as Googlebot can be verified through DNS and pass without a challenge, while all other crawlers get a harder proof-of-work challenge (see `BOTLOCK_CRAWLER_FACTOR`). The script is designed to be easy to use and configure.

## Installation

BOTLOCK requires PHP 8.2 or newer.

### Using the PHAR file (recommended)

Download the latest version of the PHAR file from the [releases page](https://github.com/ericges/botlock/releases/latest).

Place the `botlock.phar` file in the root directory of your website (or any other directory that can be accessed by your web server).

In any case, you must enable the script by setting the `BOTLOCK_ENABLED` environment variable to `yes`/`1`/`true`/`on` in your web server configuration.
This way, you can easily opt in or out of using BOTLOCK depending on the traffic your website receives.

> [!NOTE]
> - Make sure to replace `/path/to/botlock.phar` with the actual path to the `botlock.phar` file on your server.
> - The path should be absolute and accessible by the web server user.
> - Make sure to set the correct permissions for the file so that it can be executed by the web server.
> - Also, ensure that the `auto_prepend_file` directive is not overridden in your web server configuration or in any other `.htaccess` or `php.ini` files.

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

> [!NOTE]
> In a shared hosting environment, you may not have access to the `php.ini` file.

If you want to prepend the script to all requests without modifying your web server configuration, you can do so by adding the following line to your `php.ini` file or by using a `.user.ini` file in your website's root directory:

```ini
auto_prepend_file = "/path/to/botlock.phar"
```

> [!NOTE]
> Make sure to set the `BOTLOCK_ENABLED` environment variable in your web server configuration as well, as the script will not run if this variable is not set.

If you are using a custom PHP-FPM pool, you can also set the `auto_prepend_file` directive in the pool configuration file (i.e. `/etc/php-fpm.d/www.conf`):

```ini
php_admin_value[auto_prepend_file] = "/path/to/botlock.phar"
```

### Using Composer

If you prefer to use Composer, you can install BOTLOCK as a dependency in your project. Run the following command:

```bash
composer require ericges/botlock
```

Then, at the beginning of your PHP script, after requiring Composer's autoload file, add the following line:

```php
\GES\Botlock\Kernel::boot(__DIR__ . '/vendor/ericges/botlock')
    ->handleRequest(\GES\Botlock\Http\Request::fromGlobals());
```

The argument to `boot()` is the package root, i.e. the directory that contains BOTLOCK's `templates/` and `translations/` folders.

> [!NOTE]
> Not prepending the script to all requests will prevent the script from blocking bad bots accessing static files (e.g. images, CSS, JS).
> Additionally, since all your Composer dependencies are loaded before the script is executed, it may slow down your website as even requests that are blocked will require more resources to process.

## Configuration

All configuration is read from environment variables. Boolean values accept `1`, `true`, `on`, or `yes` to enable an option and `0`, `false`, `off`, or `no` to disable it. List values may be comma-separated or JSON arrays of strings, for example `127.0.0.1,192.0.2.10` or `["127.0.0.1", "192.0.2.10"]`.

### General and proof-of-work settings

| Environment variable | Default | Description |
| --- | --- | --- |
| `BOTLOCK_ENABLED` | Disabled | Enables BOTLOCK. This must be enabled when using `bootstrap.php` or the PHAR as an `auto_prepend_file`. |
| `BOTLOCK_FAIL_OPEN` | Disabled | When enabled, boot errors (for example an unwritable state directory) let the request through to your application with a `Botlock-Error` header instead of answering `500`. Disabled means BOTLOCK fails closed and blocks the request. |
| `BOTLOCK_INSTANCE_ID` | MD5 hash of the source directory | Identifies this BOTLOCK instance and separates its secret and global rate-limit state from other instances using the same state directory. |
| `BOTLOCK_STATE_DIR` | System temporary directory plus `/botlock` | Writable directory used for the generated secret, the rate-limit state files, pending challenge tickets (`tickets/`) and the cached challenge pages (`botlock_challenge_<instance-id>_<lang>_<version>.html`). BOTLOCK creates it with mode `0700` and keeps the files inside owner-only (`0600`); an existing directory or file with wider permissions is tightened where the PHP user is allowed to do so, but its ownership is not verified. On a host shared with other local accounts, point this at a directory only the PHP user can reach (inside the application's private storage, not under the system temporary directory), because another account could pre-create the default path. See [Shared hosting](#shared-hosting). |
| `BOTLOCK_SECRET` | Generated automatically | Secret used to sign challenges and session data. When unset, a 32-character secret is generated once, atomically, and stored owner-only as `botlock_secret_<instance-id>` in `BOTLOCK_STATE_DIR`. Set it explicitly when several hosts share sessions, or on shared hosts, so the signing secret never depends on the state directory. |
| `BOTLOCK_POW_ALGORITHM` | `sha256` | Hash algorithm used for proof-of-work challenges. Allowed values are `sha256`, `sha384`, and `sha512`. |
| `BOTLOCK_EXPIRE` | `3600` | Session lifetime in seconds. A challenge ticket always expires after five minutes. |
| `BOTLOCK_MAX_NUMBER` | `50000` | Base upper bound for the number searched by a proof-of-work challenge. |
| `BOTLOCK_CRAWLER_FACTOR` | `15` | Multiplies proof-of-work difficulty for crawlers that are not listed as good bots. The effective minimum is `1`. |
| `BOTLOCK_MIN_SOLVE_MS` | `1000` | Minimum time in milliseconds between issuing a challenge and accepting its solution. Faster solutions are rejected and spend the ticket; the challenge page waits out the rest of this time before it submits. `0` disables the check. |

### Language

The challenge page is served in the language negotiated from the browser's `Accept-Language` header: language ranges are ranked by their `q` value, matched on the primary subtag (`de-AT` selects `de`), and `nb`/`nn` map to `no`. When none of the shipped languages in `translations/` are acceptable, English is used. There is no setting for this. The response carries `Content-Language` and `Vary: Accept-Language`. Each language is rendered once and then served from a cached file in `BOTLOCK_STATE_DIR`; the cache refreshes itself when the template or a translation file changes.

### Bot detection, proxies, and exclusions

| Environment variable | Default | Description |
| --- | --- | --- |
| `BOTLOCK_IGNORE_IPS` | Empty | List of exact client IP addresses that bypass BOTLOCK. |
| `BOTLOCK_IGNORE_USER_AGENTS` | Empty | List of User-Agent substrings that bypass BOTLOCK. Matched case-insensitively. |
| `BOTLOCK_IGNORE_URLS` | Empty | List of absolute URL prefixes that bypass BOTLOCK. |
| `BOTLOCK_GOOD_BOTS` | `Googlebot`, `AdsBot`, `Bingbot`, `DuckDuckBot`, `Exabot`, `facebot` | CrawlerDetect names treated as good bots (matched case-insensitively). Good bots pass without a challenge while the effective threat level is below `2`; at levels `2` and `3` they get an easy, self-starting challenge, and at level `4` they are refused like everyone else. |
| `BOTLOCK_VERIFY_BOTS` | `google` | Bot providers to verify using DNS. Currently only `google` is supported (covers `Googlebot` and `AdsBot`). A good bot of a listed provider is only exempted after its IP passed the reverse-DNS check; a failed check raises the request to threat level `2`. Good bots without a listed provider are trusted by User-Agent alone. Set an empty value or `[]` to disable provider verification. |
| `BOTLOCK_TRUSTED_PROXIES` | Loopback, private and link-local ranges | List of proxy IP addresses or CIDR ranges (for example `10.0.0.0/8`, `2001:db8::/32`) allowed to supply the `X-Forwarded-For`, `X-Real-Ip`, `Client-Ip` and `X-Forwarded-Proto` headers. Unset trusts peers in `127.0.0.0/8`, `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `169.254.0.0/16`, `::1/128`, `fc00::/7` and `fe80::/10`. An empty value or `[]` disables forwarding headers entirely. An explicit list replaces the default. Malformed entries stop BOTLOCK from booting. See [Reverse proxies](#reverse-proxies). |
| `BOTLOCK_DNS_CHECKS` | Enabled | Enables DNS verification for providers selected by `BOTLOCK_VERIFY_BOTS`. Disabling it trusts all good bots by User-Agent alone. |
| `BOTLOCK_EXTERNAL_SCHEME` | `auto` | Scheme visitors use to reach the site. `auto` takes `HTTPS`/port 443 as seen by PHP and `X-Forwarded-Proto` from a trusted proxy. Set `https` (or `http`) to force it, for example when the proxy does not send the header or when an appending proxy chain has a plain-HTTP inner leg. Determines the `Secure` flag of the session cookie, the session issuer and reset redirects. See [TLS termination](#tls-termination). |

#### Reverse proxies

BOTLOCK rate-limits and fingerprints visitors by their IP address. Behind a reverse proxy, load balancer or CDN every request arrives from the proxy, and the real client address only travels in a forwarding header such as `X-Forwarded-For`. Any client can send such a header, so BOTLOCK only reads it when the directly connected peer is a trusted proxy.

By default, peers on loopback, private (RFC 1918), link-local and IPv6 unique-local addresses are trusted, which covers the usual same-host or same-network reverse proxy, Docker or Kubernetes ingress and cloud load balancers inside a VPC. A proxy with a public address, such as a CDN, must be listed explicitly. Setting `BOTLOCK_TRUSTED_PROXIES` replaces the default list; single addresses and CIDR ranges may be mixed:

```
BOTLOCK_TRUSTED_PROXIES=10.0.0.5
BOTLOCK_TRUSTED_PROXIES=10.0.0.0/8,2001:db8::/32
BOTLOCK_TRUSTED_PROXIES=
```

The last form (an empty value or `[]`) disables forwarding headers entirely. Consider it when other machines on your private network can reach the application directly and bypass the proxy, because such a peer could spoof `X-Forwarded-For`.

When `X-Forwarded-For` arrives from a trusted proxy, BOTLOCK reads the chain from the right: trailing entries that are themselves trusted proxies are skipped and the first remaining entry is the client. Entries a client adds itself sit further left and are never reached, so proxies that append to the header are safe. Every proxy in the chain must therefore be listed, including a CDN in front of your own load balancer. A forgotten proxy with a public address is silently taken for the visitor, so all visitors share that address and are rate-limited together. Only a forgotten hop with a private address is reported, as `Botlock-Warning: forwarding header X-Forwarded-For ignored: hop 10.1.2.3 is not a trusted proxy`. When `X-Forwarded-For` is present it is authoritative; `X-Real-Ip` and `Client-Ip` are only used when the proxy sets one of them instead. Entries with a port (`203.0.113.7:51234`) and bracketed IPv6 addresses are accepted.

When a forwarding header is present but cannot be used, the connecting address is used instead and the response carries a `Botlock-Warning` header naming the ignored header and the reason, for example `forwarding header X-Forwarded-For ignored: peer 203.0.113.10 is not a trusted proxy`. Check it with `curl -sI -H 'X-Forwarded-For: 198.51.100.7' https://your-site/ | grep Botlock` after deploying behind a proxy.

To confirm which address BOTLOCK actually resolved, request the status endpoint, which returns how BOTLOCK sees the calling client as JSON:

```bash
curl -s -H 'X-Forwarded-For: 198.51.100.7' 'https://your-site/?_botlock=status'
```

The response contains `subject` (the client fingerprint, derived from the resolved IP address and a few request headers), `threat_level`, `threat_level_global`, `threat_level_individual`, `individual_rate`, `crawler_verification` (`verified`, `failed`, `unverified` or `not_applicable` for crawlers, `null` otherwise) `passed` (whether the session holds a grant for at least the current threat level) and `grant_level` (the threat level the grant was issued for, `0` without one). The fingerprint changes with the resolved client IP: if two requests with different `X-Forwarded-For` values return the same `subject`, the header is being ignored and the `Botlock-Warning` header of a plain request tells you why.

> **Breaking change:** earlier releases trusted forwarding headers from every source when `BOTLOCK_TRUSTED_PROXIES` was unset. Installations behind a proxy with a public address must now list it, otherwise all visitors share the proxy address and are rate-limited together.

#### TLS termination

When the proxy terminates TLS and talks plain HTTP to PHP, PHP sees an `http://` request. BOTLOCK would then issue the session cookie without the `Secure` flag and sign sessions for an `http://` issuer, so the grant could travel over plain HTTP. To avoid that, BOTLOCK applies `X-Forwarded-Proto` when it arrives from a trusted proxy, exactly like the client-address headers above, and sets the scheme visitors actually used. If the web server already rewrites `HTTPS=on` from that header, nothing else is needed; if the proxy does not send the header at all, set `BOTLOCK_EXTERNAL_SCHEME=https` to force it. An `X-Forwarded-Proto` header from an untrusted peer is ignored and reported as `Botlock-Warning: forwarding header X-Forwarded-Proto ignored: peer 203.0.113.10 is not a trusted proxy`.

BOTLOCK reads the **last** `X-Forwarded-Proto` value, which is the one written by the trusted proxy that connected to PHP. A value the client sends itself sits further left and is never used, whether the proxy overwrites the header or appends to it. The one topology this does not cover is a chain in which an inner hop appends its own plain-HTTP leg, for example a CDN speaking HTTP to a load balancer that appends `http` before handing the request to PHP; the last value is then `http` although the visitor used HTTPS. Set `BOTLOCK_EXTERNAL_SCHEME=https` for such deployments.

Because the session issuer changes from `http://` to `https://` when this takes effect, existing session cookies become invalid once and visitors solve the challenge again.

#### Shared hosting

BOTLOCK defends a site against traffic from outside; other local accounts on the same machine are outside its threat model. The default state directory lives under the system temporary directory at a predictable path, and BOTLOCK does not verify who owns a directory that already exists there. If other accounts on the host are not trusted, set `BOTLOCK_STATE_DIR` to a directory only the PHP user can reach and set `BOTLOCK_SECRET` explicitly, so neither the signing secret nor the cached challenge pages can be planted by a neighbour.

### Threat and rate-limit settings

BOTLOCK calculates separate global and per-client threat levels and uses the higher
of the two as the effective threat level for a request. Level `0` means that no
configured threshold has been reached, so the request passes through without a
challenge. The global level goes up to `3`; only the per-client rate reaches
level `4`, because a global block would lock out every visitor during a traffic
spike. The elevated threat levels behave as follows:

| Threat level | Browsers | Crawlers that are not trusted good bots | Trusted good bots |
| --- | --- | --- | --- |
| `1` | Proof of work, starts on its own | Click, then proof of work (× `BOTLOCK_CRAWLER_FACTOR`) | Pass without a challenge |
| `2` | Click, then proof of work | Click, then proof of work (× factor) | Easy proof of work, starts on its own |
| `3` | Slider captcha, then proof of work | Slider captcha, then proof of work (× factor) | Easy proof of work, starts on its own |
| `4` | `429 Too Many Requests` with `Retry-After`, no challenge | same | same |

A good bot is trusted when it is listed in `BOTLOCK_GOOD_BOTS` and, for providers
listed in `BOTLOCK_VERIFY_BOTS`, passed the DNS check; a failed check raises the
request to at least level `2`.

The slider captcha shows a picture with a gap and a matching piece that the
visitor slides into place. It works with the mouse, by touch, with the arrow keys
or by clicking the track. The target position stays on the server. It
makes generic automation more expensive; like every self-hosted captcha it does
not stop a determined attacker with image processing, and visitors who cannot
see the picture cannot solve it, so level `3` should stay reserved for real abuse.

A completed challenge grants the session for the threat level it was issued at,
for the configured `BOTLOCK_EXPIRE` lifetime. When the level rises above that,
the next page request is challenged again at the higher level. Explicit IP,
User-Agent, and URL exclusions bypass all elevated levels, including the `429`,
and are not counted toward any threshold, so monitoring checks or your own
addresses never raise the threat level.

Each challenge is a single-use ticket kept in `BOTLOCK_STATE_DIR` for five
minutes. `GET ?_botlock=challenge` issues it and names the required interaction
(`int`: `none`, `click` or `slider`) together with the ticket's threat level; the
proof of work is included only when no interaction is required, otherwise
`POST ?_botlock=challenge` hands it out once the interaction is reported. The
proof-of-work signature covers the ticket id, level and interaction, so a
solution cannot be moved to another ticket. `POST ?_botlock=verify` spends the
ticket whatever the outcome. A missed slider spends it as well, so every guess
costs a new challenge. When the threat level has risen above the ticket's
before it is redeemed, both endpoints answer `409` and the page starts over with
a challenge for the new level.

When the rate-limit state in `BOTLOCK_STATE_DIR` cannot be read or written (for
example a full disk or lock contention), the affected level is reported as `1`
so that a storage fault never disables the challenge. The status endpoint then
shows `individual_rate` as `null`.

| Environment variable | Default | Description |
| --- | --- | --- |
| `BOTLOCK_THREAT_LEVEL_OVERRIDE` | Unset | Fixed integer threat level that bypasses rate-based threat evaluation when set. Values are clamped to `0` through `4`. |
| `BOTLOCK_ENABLE_RATE_LIMIT` | Enabled | Master switch for rate-based threat evaluation. It is effective only when at least one of the global or individual rate limits is enabled. |
| `BOTLOCK_ENABLE_GLOBAL_RATE_LIMIT` | Enabled | Enables global request tracking and threat-level calculation. |
| `BOTLOCK_LEVEL_1_THRESHOLD_GLOBAL` | `120` | Weighted five-minute global request score that activates threat level 1. |
| `BOTLOCK_LEVEL_2_THRESHOLD_GLOBAL` | `300` | Weighted five-minute global request score that activates threat level 2. |
| `BOTLOCK_LEVEL_3_THRESHOLD_GLOBAL` | `600` | Weighted five-minute global request score that activates threat level 3. |
| `BOTLOCK_LEVEL_DECAY_GRACE_PERIOD` | `300` | Seconds to retain the current global threat level after traffic falls below its threshold. |
| `BOTLOCK_ENABLE_INDIVIDUAL_RATE_LIMIT` | Enabled | Enables per-client-fingerprint request tracking and threat-level calculation. |
| `BOTLOCK_INDIVIDUAL_RATE_WINDOW_SEC` | `60` | Rolling window in seconds used to count requests for each client fingerprint. |
| `BOTLOCK_LEVEL_1_THRESHOLD_INDIVIDUAL` | `60` | Requests per individual window that activate threat level 1. |
| `BOTLOCK_LEVEL_2_THRESHOLD_INDIVIDUAL` | `90` | Requests per individual window that activate threat level 2. |
| `BOTLOCK_LEVEL_3_THRESHOLD_INDIVIDUAL` | `120` | Requests per individual window that activate threat level 3. |
| `BOTLOCK_LEVEL_4_THRESHOLD_INDIVIDUAL` | `180` | Requests per individual window that activate threat level 4: every request of that client is answered with `429` and no challenge until its rate drops. `GET ?_botlock=status` stays reachable. |
| `BOTLOCK_GC_PROBABILITY` | `1000` | Roughly one request in this many sweeps stale per-client state files out of `BOTLOCK_STATE_DIR`. Each sweep inspects up to 500 files, starting at a random shard so that every shard is reached over time. `0` disables the sweep. |

## Developers

BOTLOCK needs PHP 8.2 or newer and Composer. A [DDEV](https://ddev.com/) project is checked in, so the quickest way to a working setup is:

```bash
ddev start
ddev composer install
```

This serves the `demo/` directory at `https://botlock.ddev.site` with Apache and PHP 8.3. Its `.user.ini` prepends a demo shim that runs BOTLOCK only for the protected area `/protected/`; the dashboard at `/` stays open whatever the settings are. By default the demo uses the rate-limit sandbox: rate-based threat levels with low thresholds (10/20/30/40 requests per minute per client), so the protected page passes at first and a few reloads or a request burst escalate it up to the level-4 `429`. The dashboard has two panes:

- the left pane shows the live `?_botlock=status` of the selected identity as threat-level meters with the individual rate against its thresholds, a history of recent samples and any `Botlock-Error` or `Botlock-Warning` header; below it presets (always challenge, slider captcha, off, a rate-limit sandbox with low thresholds, library defaults), every `BOTLOCK_*` setting except the secret, state directory and instance ID, grouped as in the tables above, with change markers, per-field reset and a sticky save bar, and copyable `curl` recipes;
- the right pane stays in view with tools (request burst, clearing the rate-limit state, rotating the generated secret, resetting the challenge), an identity bar and a frame showing the protected page as BOTLOCK serves it to that identity, next to a request log.

An identity is a forged client: a User-Agent, a client IP sent as `X-Forwarded-For`, an Accept-Language and extra headers. For any identity other than "This browser" the frame, status, burst and reset go through the relay `/_forge.php/<identity>/protected/…`, which repeats each request from inside the container with the identity's headers. Loopback is a trusted proxy by default, so BOTLOCK takes the forged address as the client IP, and `Host` and `X-Forwarded-Proto` are passed on so it sees the visitor's origin. Because the challenge page's own requests travel through the same relay, the challenge runs and is solved in the browser but for the forged fingerprint; the relay renames and path-scopes the session cookie per identity, so each identity keeps its own grant and never touches the browser's own session. The request log tab lists every relayed exchange (what was sent to BOTLOCK, the response headers and the start of the body). The identity is base64url-encoded in the relay URL, so nothing is stored for it.

Presets, tools and saving apply in place and reload the frame (switch off "auto-reload frame" to control exactly which requests count); without JavaScript the same forms post to `/_demo.php` and redirect back. Settings are stored in the gitignored `.demo/settings.json` and exported with `putenv()` before `bootstrap.php` is required, so they apply on the next request without editing server configuration; the demo state directory is `.demo/state/`. The dashboard, `/_demo.php` and `/_forge.php` never run BOTLOCK; should the settings still get in the way, open `https://botlock.ddev.site/_demo.php?reset=1` (or delete `.demo/settings.json`) to restore the defaults. Do not add `BOTLOCK_*` `SetEnv` lines to the DDEV setup: FastCGI parameters would shadow the values the shim sets.

Without DDEV, any local PHP setup works as long as `bootstrap.php` (or `demo/_lib/prepend.php` for the demo) is configured as `auto_prepend_file`, and `BOTLOCK_ENABLED` is set when prepending `bootstrap.php` directly.

### Layout

| Path | Contents |
| --- | --- |
| `src/Kernel.php` | Boots the configuration and assembles the middleware pipeline. |
| `src/Middleware/` | One class per request-processing step, in the order listed in `Kernel::handleRequest()`. |
| `src/Action/` | Handlers for the `?_botlock=<action>` endpoints: `challenge` (`GET` issues a ticket, `POST` reports the interaction), `verify`, `reset` and `status`. |
| `src/Config/` | Typed configuration objects, each with a `fromEnv()` factory reading `BOTLOCK_*` variables. |
| `src/Manager/`, `src/Threat/` | Bot detection and rate-limit state. |
| `src/Challenge/` | Proof of work, the per-level interaction policy, single-use challenge tickets and their store, and the slider puzzle. |
| `src/Image/` | A dependency-free PNG encoder for the slider puzzle. |
| `src/Crawler/` | Crawler verification state and the DNS verifier behind `CrawlerVerifier`. |
| `src/Filesystem/` | Creates the state directory and keeps its contents owner-only. |
| `templates/challenge.php` | The browser challenge page, a native PHP template rendered once per language and cached in the state directory. |
| `translations/` | One `<code>.php` file per language returning the challenge page strings. |
| `src/I18n/`, `src/Template/` | `Accept-Language` negotiation, translation loading, template rendering and the rendered-page cache. |
| `bootstrap.php` | The prepend entry point, also used as the PHAR stub. |
| `build-phar.php` | Builds the release archive. |
| `demo/` | The DDEV docroot: open dashboard (`index.php`), settings endpoint (`_demo.php`), request forging relay (`_forge.php`), the protected area (`protected/`), and the prepend shim, settings schema, presets, identities and relay log in `_lib/`. Not part of the library or the PHAR. |

### Testing and checks

```bash
ddev composer test                          # PHPUnit
ddev composer validate --no-check-publish   # Composer metadata
ddev exec sh -c "find src tests templates translations demo -name '*.php' -print0 | xargs -0 -n1 php -l"
```

Unit tests live in `tests/`, mirroring `src/`. Construct the `Config\*` value objects directly instead of setting environment variables, and use the `Requests` factory, `InMemoryThreatStateStore`, `InMemoryChallengeTicketStore` and `StubCrawlerVerifier` from `tests/Support/`. CI runs the same checks on PHP 8.2 through 8.5 for every push and pull request.

### Building the PHAR

```bash
ddev exec php build-phar.php
```

The build needs `phar.readonly=0`, which the DDEV container already sets. The resulting `botlock.phar` is ignored by Git. Releases are built automatically: creating a GitHub release triggers the "Build and Release PHAR" workflow, which runs the tests, builds the archive and uploads it as a release asset.
