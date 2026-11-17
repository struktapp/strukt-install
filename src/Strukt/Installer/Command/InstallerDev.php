<?php

declare (strict_types = 1);

namespace Strukt\Installer\Command;

use RuntimeException;
use Strukt\Console\Runtime\CommandInterface;
use Strukt\Installer\DevInstaller;
use Throwable;

/**
 * @command new:dev
 * @description Install Strukt packages from GitHub master archives
 * @usage new:dev <app_name> [--refresh]
 *
 * @argument app_name
 * @description Application Name
 *
 * @option refresh
 * @flag
 * @short r
 * @description Re-download packages already present in the cache
 */
final class InstallerDev implements CommandInterface
{
    public function execute(array $arguments, array $options): int
    {
        $appName = $arguments['app_name'] ?? null;
        if (!is_string($appName) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $appName)) {
            throw new RuntimeException('Argument [app_name] must be a valid directory name.');
        }

        $root = getcwd();
        if ($root === false) {
            throw new RuntimeException('Unable to determine the current working directory.');
        }

        $target = $root . DIRECTORY_SEPARATOR . $appName;
        if (file_exists($target)) {
            throw new RuntimeException(sprintf('Target [%s] already exists.', $appName));
        }

        $setting = parse_ini_file(__DIR__ . '/../../../../strukt.ini', true);
        if ($setting === false) {
            throw new RuntimeException('Unable to read strukt.ini.');
        }

        $config = DevInstaller::normalizeConfig($setting);
        $stage = sys_get_temp_dir() . '/strukt-dev-master-' . bin2hex(random_bytes(8));
        if (!mkdir($stage, 0775, true) && !is_dir($stage)) {
            throw new RuntimeException(sprintf('Unable to create staging directory: %s', $stage));
        }

        $created = false;

        try {
            $refresh = !empty($options['refresh']);
            $archives = [];
            $downloads = [];
            $slugs = array_merge([$config['skeleton']], $config['packages']);
            foreach ($slugs as $slug) {
                if (!$refresh && DevInstaller::isCached($config, $slug)) {
                    echo sprintf("Using cached %s\n", $slug);
                    continue;
                }

                $zip = $stage . '/' . $slug . '.zip';
                $url = DevInstaller::archiveUrl(
                    $config['github_org'],
                    $config['branch'],
                    $slug
                );

                echo sprintf("Downloading %s from %s\n", $slug, $url);
                DevInstaller::download($url, $zip);
                $archives[$slug] = $zip;
                $downloads[$slug] = true;
            }

            if (!mkdir($target, 0775, true) && !is_dir($target)) {
                throw new RuntimeException(sprintf('Unable to create application directory: %s', $target));
            }
            $created = true;

            $skeletonPath = DevInstaller::packagePath($config, $config['skeleton']);
            if (isset($downloads[$config['skeleton']])) {
                echo sprintf("Extracting skeleton %s\n", $config['skeleton']);
                DevInstaller::extract(
                    $archives[$config['skeleton']],
                    $skeletonPath,
                    $config['skeleton'],
                    $config['branch']
                );
            }

            echo sprintf("Copying skeleton %s\n", $config['skeleton']);
            DevInstaller::copyDirectory($skeletonPath, $target);

            foreach ($config['packages'] as $slug) {
                if (!isset($downloads[$slug])) {
                    continue;
                }

                echo sprintf("Extracting package %s\n", $slug);
                DevInstaller::extract(
                    $archives[$slug],
                    DevInstaller::packagePath($config, $slug),
                    $slug,
                    $config['branch']
                );
            }

            $skeletonComposer = DevInstaller::readComposer($target . '/composer.json');
            $rootComposer = DevInstaller::rewriteRootComposer($skeletonComposer, $config);
            $rootComposer = DevInstaller::rewritePostCreateScripts($rootComposer, $appName);
            DevInstaller::writeComposer($target . '/composer.json', $rootComposer);

            foreach ($config['packages'] as $slug) {
                $path = DevInstaller::packagePath($config, $slug) . '/composer.json';
                $composer = DevInstaller::readComposer($path);
                DevInstaller::writeComposer(
                    $path,
                    DevInstaller::rewritePackageComposer($composer, $config['version'])
                );
            }

            $lock = $target . '/composer.lock';
            if (is_file($lock)) {
                unlink($lock);
            }

            $code = $this->run(sprintf(
                'composer install --prefer-dist --no-interaction --working-dir=%s 2>&1',
                escapeshellarg($target)
            ));
            if ($code !== 0) {
                return $code;
            }

            DevInstaller::relinkBinaries($target);
            DevInstaller::seedCommandConfig($target);

            return $this->run(sprintf(
                'composer run-script post-create-project-cmd --no-interaction --working-dir=%s 2>&1',
                escapeshellarg($target)
            ));
        } catch (Throwable $exception) {
            if ($created) {
                DevInstaller::removeDirectory($target);
            }

            throw $exception;
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
