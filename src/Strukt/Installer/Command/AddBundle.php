<?php

declare (strict_types = 1);

namespace Strukt\Installer\Command;

use RuntimeException;
use Strukt\Console\Runtime\CommandInterface;
use Strukt\Installer\DevInstaller;

/**
 * @command add
 * @description Install the latest stable Strukt bundle from Packagist
 * @usage add <bundle> [<app_name>]
 *
 * @argument bundle
 * @description Bundle name or package slug
 * @required
 *
 * @argument app_name
 * @description Application directory
 */
final class AddBundle implements CommandInterface
{
    public function execute(array $arguments, array $options): int
    {
        $name = $arguments['bundle'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            throw new RuntimeException('Argument [bundle] is required.');
        }

        $project = $arguments['app_name'] ?? '.';
        if (!is_string($project) || trim($project) === '') {
            $project = '.';
        }

        $root = realpath($project);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException(sprintf('Project [%s] does not exist.', $project));
        }

        if (!is_file($root . '/composer.json')) {
            throw new RuntimeException(sprintf('Project [%s] is not a Composer project.', $project));
        }

        $package = DevInstaller::bundlePackageName($name);
        $version = DevInstaller::latestStableVersion(DevInstaller::packagistVersions($package));
        if ($version === null) {
            throw new RuntimeException(sprintf('Bundle [%s] has no stable release on Packagist.', $package));
        }

        $command = sprintf(
            'composer require %s --working-dir=%s --no-interaction --prefer-dist 2>&1',
            escapeshellarg($package . ':' . $version),
            escapeshellarg($root)
        );

        \switchChannels();
        echo $command . "\n";

        $process = \process([$command], static function (?string $line): void {
            if ($line !== null) {
                echo $line;
            }
        });

        return $process->resource(0)->close();
    }
}
