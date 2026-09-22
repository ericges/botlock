# Repository Guidelines

## Project Structure & Module Organization

Botlock is a PHP 8.2+ library distributed through Composer and as a PHAR. Production code lives in `src/` under the `GES\Botlock` PSR-4 namespace. Request processing is assembled in `src/Kernel.php`; middleware belongs in `src/Middleware/`, `?_botlock=` action handlers in `src/Action/`, HTTP abstractions in `src/Http/` (per-request state travels in `Http\RequestContext`), typed configuration objects in `src/Config/`, detection and threat services in `src/Manager/`, rate-limit persistence behind `Threat\ThreatStateStore` in `src/Threat/`, and proof-of-work logic in `src/Challenge/`. The browser challenge document is `assets/challenge.html`. `bootstrap.php` is the prepend entry point and PHAR stub, `index.php` is the local demonstration page, and `build-phar.php` creates the release artifact. The "Developers" section of `README.md` is the public counterpart of this file; keep the two in sync when commands or layout change.

## Build, Test, and Development Commands

- `ddev start` launches the Apache/PHP 8.3 development site at `https://botlock.ddev.site`. The checked-in `.htaccess` and `.user.ini` prepend `bootstrap.php`, enable BOTLOCK and pin `BOTLOCK_THREAT_LEVEL_OVERRIDE=2`, so every request is challenged. The host has no PHP binary; run PHP and Composer through `ddev`.
- `ddev composer install` installs the locked dependency set and generates autoload files.
- `ddev composer validate --no-check-publish` checks Composer metadata.
- `ddev exec sh -c "find src -name '*.php' -print0 | xargs -0 -n1 php -l"` syntax-checks every source file.
- `ddev exec php build-phar.php` builds `botlock.phar`; the container sets `phar.readonly=0` via `.ddev/php/php-phar.ini`. The archive is generated and must not be committed. Release archives are built by `.github/workflows/release-phar.yaml` when a GitHub release is created.

## Coding Style & Naming Conventions

Use four-space indentation, strict type declarations in new PHP files, typed properties and return values, and trailing commas in multiline argument lists. Follow PSR-4 placement: `GES\Botlock\Http\Request` belongs in `src/Http/Request.php`. Classes and enums use `PascalCase`; methods, properties, and local variables use `camelCase`; constants and environment keys use `UPPER_SNAKE_CASE`. Middleware classes should implement `MiddlewareInterface` and end in `Middleware`. No formatter is configured, so keep edits consistent with surrounding code and avoid unrelated reformatting.

## Testing Guidelines

Unit tests live in `tests/` (PHPUnit 11, namespace `GES\Botlock\Tests`, mirroring `src/`); run them with `ddev composer test`. `tests/Support/` holds the `InMemoryThreatStateStore` double and a `Requests` factory; prefer constructing the `Config\*` value objects directly over `putenv()`. Before submitting, run the tests, Composer validation and the full PHP syntax check, then exercise affected request paths through DDEV. For middleware changes, verify pass-through behavior plus relevant `_botlock` actions such as `challenge`, `verify`, `reset`, or `status`. CI (`.github/workflows/ci.yaml`) runs the same checks on PHP 8.2–8.5 for every push and pull request.

## Commit & Pull Request Guidelines

Recent commits use short, lowercase imperative summaries such as `add botlock action handling` and `refactor crawler awareness`. Keep commits focused and avoid committing `vendor/`, `botlock.phar`, IDE files, or runtime state. Pull requests should explain behavior changes, list validation performed, link related issues, and include screenshots only when `assets/challenge.html` or the demo UI changes. Call out configuration or security implications for new `BOTLOCK_*` settings.
