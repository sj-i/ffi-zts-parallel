<?php
declare(strict_types=1);

namespace SjI\FfiZts\Parallel;

use SjI\FfiZts\Platform;
use SjI\FfiZts\Exception\InstallException;

/**
 * Composer post-install hook for the parallel binary.
 *
 * Mirrors SjI\FfiZts\Installer but for parallel.so. Two artefacts
 * may be present in the release tarball, per docs/DESIGN.md §5.3:
 *   - extensions/no-debug-zts-<api>/parallel.so  (vanilla, for
 *     native ZTS CLI use; we keep it for forensic/debug reasons)
 *   - extensions/ffi-zts/parallel.so             (FFI-linked, what
 *     the embed actually uses)
 *
 * If the release ships only the vanilla variant, the runtime ELF
 * patcher in Parallel::boot() will produce the linked variant on
 * first use into the disk cache.
 */
final class Installer
{
    public static function fetchBinaries(?object $event = null): void
    {
        $binDir = Parallel::packageRoot() . '/bin';
        if (!is_dir($binDir) && !@mkdir($binDir, 0755, true) && !is_dir($binDir)) {
            throw new InstallException("unable to create {$binDir}");
        }

        $marker = $binDir . '/parallel.so';
        $linked = $binDir . '/extensions/ffi-zts/parallel.so';
        if (is_file($marker) || is_file($linked)) {
            self::log($event, "ffi-zts-parallel: parallel.so already present");
            return;
        }

        Platform::assertSupported();

        $url = self::releaseUrl();
        self::log($event, "ffi-zts-parallel: fetching {$url}");

        $bytes = @file_get_contents($url);
        if ($bytes === false) {
            throw new InstallException("unable to download release asset: {$url}");
        }

        $tmp = tempnam(sys_get_temp_dir(), 'ffi-zts-parallel-');
        @file_put_contents($tmp, $bytes);
        try {
            $phar = new \PharData($tmp);
            $phar->extractTo($binDir, null, true);
        } catch (\Throwable $e) {
            throw new InstallException('unable to extract parallel release archive: ' . $e->getMessage(), previous: $e);
        } finally {
            @unlink($tmp);
        }

        if (!is_file($marker) && !is_file($linked)) {
            throw new InstallException(
                "expected parallel.so under {$binDir} after extraction; check the release archive layout",
            );
        }
        self::log($event, "ffi-zts-parallel: installed parallel.so under {$binDir}");
    }

    public static function releaseUrl(): string
    {
        $php  = Platform::phpAbi();
        $arch = Platform::arch();
        $libc = Platform::libc();
        $tag   = "parallel-php{$php}";
        $asset = "parallel-php{$php}-{$arch}-{$libc}.tar.gz";
        return "https://github.com/sj-i/ffi-zts-parallel/releases/download/{$tag}/{$asset}";
    }

    private static function log(?object $event, string $msg): void
    {
        if ($event !== null && method_exists($event, 'getIO')) {
            $event->getIO()->write($msg);
            return;
        }
        fwrite(STDERR, $msg . "\n");
    }
}
