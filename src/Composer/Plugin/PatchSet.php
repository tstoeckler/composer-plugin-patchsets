<?php

namespace Wieni\ComposerPatchSet\Composer\Plugin;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Package\Package;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use cweagans\Composer\Capability\Resolver\ResolverProvider as ResolverProviderInterface;
use cweagans\Composer\Capability\Patcher\PatcherProvider as PatcherProviderInterface;
use cweagans\Composer\Plugin\Patches;
use Wieni\ComposerPatchSet\Patcher\PatcherProvider;
use Wieni\ComposerPatchSet\Resolver\ResolverProvider;

class PatchSet implements PluginInterface, EventSubscriberInterface, Capable
{

    public static function getSubscribedEvents(): array
    {
        return [
            // Make sure patch lock file is removed before the patches are
            // applied (which happens with priority 10).
            PackageEvents::POST_PACKAGE_INSTALL => ['onPatchRepositoryInstall', 20],
            PackageEvents::POST_PACKAGE_UPDATE => ['onPatchRepositoryUpdate', 20],
        ];
    }

    public function getCapabilities(): array
    {
        return [
            ResolverProviderInterface::class => ResolverProvider::class,
            PatcherProviderInterface::class => PatcherProvider::class,
        ];
    }

    public function activate(Composer $composer, IOInterface $io)
    {
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
    }

    public function onPatchRepositoryInstall(PackageEvent $event)
    {
        $operation = $event->getOperation();
        if (!($operation instanceof InstallOperation)) {
            return;
        }

        $patchRepositoryNames = $this->getPatchRepositoryNames($event->getComposer());
        if (!in_array($operation->getPackage()->getName(), $patchRepositoryNames, TRUE)) {
            return;
        }

        $installedAlongWithPlugin = FALSE;
        foreach ($event->getOperations() as $operation) {
            if (($operation instanceof InstallOperation) && $operation->getPackage()->getName() === 'wieni/composer-plugin-patchsets') {
                $installedAlongWithPlugin = TRUE;
                break;
            }
        }
        if (!$installedAlongWithPlugin) {
            return;
        }

        foreach ($patchRepositoryNames as $patchRepositoryName) {
            if (!$event->getLocalRepo()->findPackage($patchRepositoryName, '*')) {
                return;
            }
        }

        $lockFilePath = Patches::getPatchesLockFilePath();
        if (!is_file($lockFilePath)) {
            return;
        }

        $event->getIO()->write(sprintf(
            '    - <info>Removing patch lock file after installing wieni/composer-plugin-patchsets and %s %s</info>',
            (count($patchRepositoryNames) > 1 )? 'patch repositories' : 'path repository',
            implode(', ', $patchRepositoryNames),
        ));
        unlink($lockFilePath);
    }

    public function onPatchRepositoryUpdate(PackageEvent $event)
    {
        $operation = $event->getOperation();
        if (!($operation instanceof UpdateOperation)) {
            return;
        }

        $composer = $event->getComposer();
        $targetPackage = $operation->getTargetPackage();
        $patchRepositoryNames = $this->getPatchRepositoryNames($composer);
        if (!in_array($targetPackage->getName(), $patchRepositoryNames, TRUE)) {
            return;
        }

        $targetPatches = $targetPackage->getExtra()['patches'] ?? [];
        $initialPatches = $operation->getInitialPackage()->getExtra()['patches'] ?? [];
        if ($initialPatches === $targetPatches) {
            return;
        }

        $lockFilePath = Patches::getPatchesLockFilePath();
        if (!is_file($lockFilePath)) {
            // If the patch files have not been locked, the patched packages have
            // not yet been installed, and, thus, do not need to be re-installed.
            return;
        }

        $io = $event->getIO();
        $io->write(sprintf(
            '    - <info>Removing patch lock file due to updated patch repository %s</info>',
            $targetPackage->getName(),
        ));
        unlink($lockFilePath);

        $packagesToInstall = [];
        foreach ($targetPatches as $targetPatchedPackage => $targetPatches) {
            // Re-install packages that are newly patched or have a different
            // set of patches...
            if (!isset($initialPatches[$targetPatchedPackage]) || ($initialPatches[$targetPatchedPackage] !== $targetPatches)) {
                $packagesToInstall[] = $targetPatchedPackage;
            }
            unset($initialPatches[$targetPatchedPackage]);
        }
        // ...or that are no longer patched but previously were.
        $packagesToInstall = array_merge($packagesToInstall, array_keys($initialPatches));

        // In case any of the packages were updated or installed as part of this
        // batch, do not re-install them.
        foreach ($event->getOperations() as $previousOperation) {
            if ($previousOperation instanceof UpdateOperation) {
                $packagesToInstall = array_diff($packagesToInstall, [$previousOperation->getTargetPackage()->getName()]);
            }
            elseif ($previousOperation instanceof InstallOperation) {
                $packagesToInstall = array_diff($packagesToInstall, [$previousOperation->getPackage()->getName()]);
            }
        }

        $newOperations = [];
        foreach ($packagesToInstall as $packageName) {
            $package = $event->getLocalRepo()->findPackage($packageName, '*');
            if ($package) {
                $io->write(sprintf(
                    '    - <info>Installing %s with updated patches</info>',
                    $packageName,
                ));
                $newOperations[] = new InstallOperation($package);
            }
        }

        $composer->getInstallationManager()->execute($event->getLocalRepo(), $newOperations);
    }

  /**
   * @return string[]
   */
    public function getPatchRepositoryNames(Composer $composer): array {
        $patchRepositoryNames = [];
        $patchRepositories = $composer->getPackage()->getExtra()['patchRepositories'];
        foreach ($patchRepositories ?? [] as $patchRepositoryJson) {
            $patchRepositoryNames[] = is_string($patchRepositoryJson)
                ? $patchRepositoryJson
                : $patchRepositoryJson['name'];
        }
        return $patchRepositoryNames;
    }

}
