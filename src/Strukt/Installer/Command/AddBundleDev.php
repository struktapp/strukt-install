<?php

declare (strict_types = 1);

namespace Strukt\Installer\Command;

use RuntimeException;
use Strukt\Console\Runtime\CommandInterface;
use Strukt\Installer\DevInstaller;
use Throwable;

/**
 * @command add:dev
 * @description Install a Strukt bundle from the development cache
 * @usage add:dev <bundle> [<app_name>] [--refresh]
 *
 * @argument bundle
 * @description Bundle name or package slug
 * @required
 *
 * @argument app_name
 * @description Application directory
 *
 * @option refresh
 * @flag
 * @short r
 * @description Re-download the bundle before installing
 */
final class AddBundleDev implements CommandInterface
{
    public function execute(array $arguments, array $options): int
    {
        $name = $arguments['bundle'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            throw new RuntimeException('Argument [bundle] is required.');
        }

        $slug = DevInstaller::bundleSlug($name);

        $project = $arguments['app_name'] ?? '.';
        if (!is_string($project) || trim($project) === '') {
            $project = '.';
        }

        $root = realpath($project);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException(sprintf('Project [%s] does not exist.', $project));
        }

        $composerPath = $root . '/composer.json';
        if (!is_file($composerPath)) {
            throw new RuntimeException(sprintf('Project [%s] is not a Composer project.', $project));
        }

        $setting = parse_ini_file(__DIR__ . '/../../../../strukt.ini', true);
        if ($setting === false) {
            throw new RuntimeException('Unable to read strukt.ini.');
        }

        $config = DevInstaller::normalizeConfig($setting);

        $refresh = !empty($options['refresh']);
        $path = $refresh ? null : DevInstaller::findBundle($config, $slug);
        if ($path !== null) {
            echo sprintf("Using cached %s\n", $slug);
        } else {
            $path = $this->downloadBundle($config, $slug);
            if ($path === null) {
                throw new RuntimeException(DevInstaller::bundleUnavailableMessage($slug));
            }
        }

        $composer = DevInstaller::readComposer($composerPath);
        DevInstaller::writeComposer(
            $composerPath,
            DevInstaller::addBundleRepository($composer, $slug, $path, $config['version'])
        );

        return $this->run(sprintf(
            'composer require strukt/%s:%s --working-dir=%s --no-interaction --prefer-dist 2>&1',
            $slug,
            $config['version'],
            escapeshellarg($root)
        ));
    }

    private function downloadBundle(array $config, string $slug): ?string
    {
        $destination = DevInstaller::packagePath($config, $slug);
        $stage = sys_get_temp_dir() . '/strukt-add-dev-' . bin2hex(random_bytes(8));
        if (!mkdir($stage, 0775, true) && !is_dir($stage)) {
            return null;
        }

        try {
            $zip = $stage . '/' . $slug . '.zip';
            $url = DevInstaller::archiveUrl(
                $config['github_org'],
                $config['branch'],
                $slug
            );

            echo sprintf("Downloading %s from %s\n", $slug, $url);
            DevInstaller::download($url, $zip);

            echo sprintf("Extracting %s\n", $slug);
            DevInstaller::extract($zip, $destination, $slug, $config['branch']);

            if (
                !is_file($destination . '/composer.json')
                || (glob($destination . '/src/Strukt/Bundle/Bundle*.php') ?: []) === []
            ) {
                DevInstaller::removeDirectory($destination);

                return null;
            }

            $composer = DevInstaller::readComposer($destination . '/composer.json');
            DevInstaller::writeComposer(
                $destination . '/composer.json',
                DevInstaller::rewritePackageComposer($composer, $config['version'])
            );

            return $destination;
        } catch (Throwable) {
            DevInstaller::removeDirectory($destination);

            return null;
        } finally {
            DevInstaller::removeDirectory($stage);
        }
    }

    private function run(string $command): int
    {
        echo $command . "\n";

        $result = \process([$command], static function (?string $line): void {
            if ($line !== null) {
                echo $line;
            }
        });

        return $result->resource(0)->close();
    }
}
