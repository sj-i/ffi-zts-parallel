<?php
declare(strict_types=1);

namespace SjI\FfiZts\Parallel\Tests;

use PHPUnit\Framework\TestCase;
use SjI\FfiZts\Parallel\Installer;

final class InstallerUrlTest extends TestCase
{
    public function testReleaseUrlMatchesDocumentedFormat(): void
    {
        $url = Installer::releaseUrl();
        $this->assertStringStartsWith(
            'https://github.com/sj-i/ffi-zts-parallel/releases/download/parallel-php',
            $url,
        );
        $this->assertMatchesRegularExpression(
            '#/parallel-php\d+\.\d+/parallel-php\d+\.\d+-(x86_64|aarch64)-(glibc|musl)\.tar\.gz$#',
            $url,
        );
    }
}
