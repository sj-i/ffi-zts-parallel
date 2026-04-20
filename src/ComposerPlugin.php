<?php
declare(strict_types=1);

namespace SjI\FfiZts\Parallel;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

/**
 * Composer plugin that fetches the per-host parallel.so artefact
 * when sj-i/ffi-zts-parallel itself is installed or updated.
 *
 * Mirrors SjI\FfiZts\ComposerPlugin in sj-i/ffi-zts but scoped to
 * the parallel.so binary. Each plugin filters by its own package
 * name so the two can coexist in a single `composer require` run
 * without stepping on each other.
 */
final class ComposerPlugin implements PluginInterface, EventSubscriberInterface
{
    private const PACKAGE_NAME = 'sj-i/ffi-zts-parallel';

    private IOInterface $io;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PackageEvents::POST_PACKAGE_INSTALL => 'onPackageInstallOrUpdate',
            PackageEvents::POST_PACKAGE_UPDATE  => 'onPackageInstallOrUpdate',
        ];
    }

    public function onPackageInstallOrUpdate(PackageEvent $event): void
    {
        $op = $event->getOperation();
        $pkg = match (true) {
            $op instanceof InstallOperation => $op->getPackage(),
            $op instanceof UpdateOperation  => $op->getTargetPackage(),
            default                         => null,
        };
        if ($pkg === null || $pkg->getName() !== self::PACKAGE_NAME) {
            return;
        }
        try {
            Installer::fetchBinaries($event);
        } catch (\Throwable $e) {
            $this->io->writeError(
                "<warning>sj-i/ffi-zts-parallel: binary fetch failed: {$e->getMessage()}</warning>",
            );
            $this->io->writeError(
                '<warning>sj-i/ffi-zts-parallel: run `vendor/bin/ffi-zts install` then refetch parallel via composer update</warning>',
            );
        }
    }
}
