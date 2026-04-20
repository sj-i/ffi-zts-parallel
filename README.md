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

The Composer post-install hook downloads the matching `parallel.so`
for your host's PHP minor / arch / libc into
`vendor/sj-i/ffi-zts-parallel/bin/`. The core package's hook does
the same for `libphp.so` under `vendor/sj-i/ffi-zts/bin/`.

## Usage

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use SjI\FfiZts\Parallel\Parallel;

Parallel::boot()->runScript(__DIR__ . '/worker.php');
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

For read-only environments (containers without a writable vendor
directory), use the in-memory variant which patches and loads
`parallel.so` via `memfd_create(2)`:

```php
Parallel::bootInMemory()->runScript('worker.php');
```

## Versioning

- `ffi-zts-parallel` major tracks the PHP minor (1.x = PHP 8.4).
- Minor / patch tracks upstream `parallel` releases plus wrapper
  fixes; bumping just the parallel version is
  `composer update sj-i/ffi-zts-parallel`.
