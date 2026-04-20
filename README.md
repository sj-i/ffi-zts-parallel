# ffi-zts-parallel

NTS-hosted wrapper around
[`pecl/parallel`](https://www.php.net/manual/en/book.parallel.php)
that runs through [`sj-i/ffi-zts`](https://github.com/sj-i/ffi-zts).

Gives a plain non-thread-safe PHP CLI access to real OS-thread
parallelism via `parallel\Runtime`, without replacing the system
PHP build. See [`docs/DESIGN.md`](https://github.com/sj-i/ffi-zts/blob/main/docs/DESIGN.md)
in the core package for the full design.

## Install

```sh
composer require sj-i/ffi-zts-parallel
```

Both `sj-i/ffi-zts` and `sj-i/ffi-zts-parallel` ship as **Composer
plugins**. On install, each downloads the pre-built binary matching
your host's PHP minor / CPU arch / libc:

- `vendor/sj-i/ffi-zts/bin/libphp.so`
- `vendor/sj-i/ffi-zts-parallel/bin/extensions/ffi-zts/parallel.so`

### Trusting the plugins

Composer 2.2+ asks you to trust a new plugin before running it.
In interactive shells you get a `(y/N)` prompt on first install.
In CI / non-interactive environments, whitelist both up front:

```sh
composer config allow-plugins.sj-i/ffi-zts true
composer config allow-plugins.sj-i/ffi-zts-parallel true
composer require sj-i/ffi-zts-parallel
```

### Manual install / retry

If either plugin was skipped (`--no-plugins`, network outage, binary
not yet published for a new PHP minor), retry the binary fetch on
demand from the root project:

```sh
vendor/bin/ffi-zts install   # fetches libphp.so
# parallel.so is re-fetched on the next `composer update sj-i/ffi-zts-parallel`.
```

## Usage

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use SjI\FfiZts\Parallel\Parallel;

Parallel::boot()
    ->runScript(__DIR__ . '/worker.php');
```

A minimal `worker.php` that fans out four `parallel\Runtime`s inside
the embed:

```php
<?php
$futures = [];
for ($i = 0; $i < 4; $i++) {
    $rt        = new parallel\Runtime();
    $futures[] = $rt->run(function (int $id): array {
        $sum = 0;
        for ($j = 0; $j < 2_000_000; $j++) $sum += $j;
        return ['id' => $id, 'pid' => getmypid(), 'zts' => PHP_ZTS, 'sum' => $sum];
    }, [$i]);
}
foreach ($futures as $f) {
    print_r($f->value());
}
```

All four workers report the same `pid` (same process) and
`zts=true` (running in the embedded ZTS interpreter).

### opcache.preload (2.x / PHP 8.5)

PHP 8.5 links opcache statically into `libphp.so`, so
`opcache.preload` works under the embed out of the box and the
preloaded classes / functions propagate into every
`parallel\Runtime` worker thread:

```php
Parallel::boot()
    ->withIniEntry('opcache.enable_cli',  '1')
    ->withIniEntry('opcache.preload',     __DIR__ . '/preload.php')
    ->withIniEntry('opcache.preload_user', get_current_user())
    ->runScript(__DIR__ . '/worker.php');
```

See [`docs/PERFORMANCE.md`](https://github.com/sj-i/ffi-zts/blob/main/docs/PERFORMANCE.md)
in the core repo for fork-vs-embed measurements.

### Read-only / containerised environments

If the vendor directory is not writable at runtime (baked into a
container image, mounted read-only, etc.), `Parallel::bootInMemory()`
skips the disk cache and patches + loads `parallel.so` via
`memfd_create(2)`:

```php
Parallel::bootInMemory()->runScript('worker.php');
```

## Versioning

Major tracks the host PHP minor:

- `ffi-zts-parallel` **1.x** targets **PHP 8.4** (host NTS / embedded ZTS).
- `ffi-zts-parallel` **2.x** targets **PHP 8.5** and picks up
  upstream's static opcache for out-of-box `opcache.preload`.

Minor / patch tracks upstream `parallel` releases plus wrapper
fixes; bumping just the parallel version is
`composer update sj-i/ffi-zts-parallel`.

## Requirements

- Linux x86_64 or aarch64, glibc 2.31+
- Host NTS PHP matching the major you install -- **1.x needs
  PHP 8.4**, **2.x needs PHP 8.5** -- with `ext-ffi` enabled
- Composer 2.2+

macOS, Windows, and musl are out of scope for the currently
supported major lines; see the design doc for why.
