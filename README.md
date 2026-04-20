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

Where `worker.php` runs inside the embedded ZTS interpreter and
uses `parallel\Runtime` directly:

```php
<?php
$rt = new parallel\Runtime();
$f  = $rt->run(function () {
    return 'hello from thread ' . zend_thread_id();
});
echo $f->value(), "\n";
```

### Read-only / containerised environments

If the vendor directory is not writable at runtime (baked into a
container image, mounted read-only, etc.), `Parallel::bootInMemory()`
skips the disk cache and patches + loads `parallel.so` via
`memfd_create(2)`:

```php
Parallel::bootInMemory()->runScript('worker.php');
```

## Versioning

- `ffi-zts-parallel` major tracks the PHP minor (1.x = PHP 8.4).
- Minor / patch tracks upstream `parallel` releases plus wrapper
  fixes; bumping just the parallel version is
  `composer update sj-i/ffi-zts-parallel`.

## Requirements

- Linux x86_64 or aarch64, glibc 2.31+
- NTS PHP 8.4 (the host) with `ext-ffi` enabled
- Composer 2.2+

macOS, Windows, and musl are out of scope for the 1.x line; see the
design doc for why.
