<?php
declare(strict_types=1);

namespace SjI\FfiZts\Parallel;

use SjI\FfiZts\Embed;
use SjI\FfiZts\Extension\Extension;
use SjI\FfiZts\FfiZts;
use SjI\FfiZts\Elf\ElfPatcher;
use SjI\FfiZts\Patch\DiskCacheSink;
use SjI\FfiZts\Patch\MemfdSink;
use SjI\FfiZts\Patch\PatchSink;
use SjI\FfiZts\Parallel\Exception\ParallelException;

/**
 * High-level entry point for running parallel\Runtime workloads
 * from an NTS host through ffi-zts.
 *
 *   Parallel::boot()->runScript('worker.php');
 *
 * Internally this:
 *   1. Boots a SjI\FfiZts\Embed against the libphp.so installed by
 *      sj-i/ffi-zts.
 *   2. Resolves a parallel.so artefact -- preferring an already
 *      ELF-patched one under bin/, falling back to patching the
 *      vanilla artefact through the configured PatchSink (disk
 *      cache by default, memfd_create if requested).
 *   3. Attaches it as an extension= ini entry on the embed.
 *
 * Exposes the underlying Embed via embed() so callers can layer
 * additional ini entries / extensions on top.
 */
final class Parallel
{
    public static function boot(
        ?string $libphpPath = null,
        ?string $parallelSoPath = null,
        ?PatchSink $patchSink = null,
    ): Embed {
        $embed = FfiZts::boot($libphpPath);

        $resolved = $parallelSoPath ?? self::defaultParallelSoPath();
        if (!is_file($resolved)) {
            throw new ParallelException(
                "parallel.so not found at {$resolved}; run `composer install` to fetch it, "
                . "or pass a path explicitly to Parallel::boot()",
            );
        }

        // If the artefact is the FFI-linked variant already (under
        // bin/extensions/ffi-zts/), use it directly. Otherwise patch
        // it on the fly through the sink so DT_NEEDED libphp.so is
        // present, per docs/DESIGN.md §5.3.
        $needsPatch = !str_contains($resolved, '/extensions/ffi-zts/');
        if ($needsPatch) {
            $sink = $patchSink ?? new DiskCacheSink(self::cacheDir());
            $libphp = basename($embed->config()->libphpPath);
            $libphpDir = dirname($embed->config()->libphpPath);
            $bytes = file_get_contents($resolved);
            $patched = ElfPatcher::patchBytes($bytes, [$libphp], $libphpDir);
            $resolved = $sink->materialize($patched, basename($resolved));
        }

        return $embed->withExtension(new Extension(
            name: 'parallel',
            path: $resolved,
        ));
    }

    public static function bootInMemory(?string $libphpPath = null, ?string $parallelSoPath = null): Embed
    {
        return self::boot($libphpPath, $parallelSoPath, new MemfdSink());
    }

    public static function defaultParallelSoPath(): string
    {
        $bin = self::packageRoot() . '/bin';
        $candidates = [
            $bin . '/extensions/ffi-zts/parallel.so',
            $bin . '/parallel.so',
        ];
        foreach ($candidates as $c) {
            if (is_file($c)) {
                return $c;
            }
        }
        return $candidates[0];
    }

    public static function packageRoot(): string
    {
        return dirname(__DIR__);
    }

    public static function cacheDir(): string
    {
        return self::packageRoot() . '/cache';
    }
}
