<?php

declare (strict_types = 1);

namespace Strukt\Installer;

use RuntimeException;
use ZipArchive;

/**
 * Builds development installations from GitHub master archives.
 */
final class DevInstaller
{
    /**
     * Normalizes and validates the [dev] installer configuration.
     *
     * @param array<string, mixed> $setting Parsed strukt.ini contents.
     * @return array{version:string,github_org:string,branch:string,skeleton:string,cache_dir:string,packages:list<string>}
     */
    public static function normalizeConfig(array $setting): array
    {
        $config = $setting['dev'] ?? null;
        if (!is_array($config)) {
            throw new RuntimeException('Missing [dev] configuration in strukt.ini.');
        }

        $version = trim((string) ($config['version'] ?? 'dev-master'));
        $org = rtrim(trim((string) ($config['github_org'] ?? '')), '/');
        $branch = trim((string) ($config['branch'] ?? 'master'));
        $skeleton = trim((string) ($config['skeleton'] ?? 'strukt'));
        $cacheDir = trim((string) ($config['cache_dir'] ?? '~/.cache/strukt'));

        if ($version === '' || $org === '' || $branch === '' || $skeleton === '' || $cacheDir === '') {
            throw new RuntimeException('Invalid [dev] configuration in strukt.ini.');
        }

        if (preg_match('/(^|\/)\.\.(\/|$)/', $cacheDir)) {
            throw new RuntimeException('[dev] cache_dir must not contain parent path segments.');
        }

        $cacheDir = self::expandHome($cacheDir);
        if (!str_starts_with($cacheDir, '/')) {
            throw new RuntimeException('[dev] cache_dir must be an absolute path or start with ~/.');
        }
        $cacheDir = rtrim($cacheDir, '/');

        $packages = $config['packages'] ?? [];
        if (is_string($packages)) {
            $packages = [$packages];
        }
        if (!is_array($packages)) {
            throw new RuntimeException('[dev] packages must be a list of package slugs.');
        }

        $packages = array_values(array_unique(array_map(
            static fn (mixed $slug): string => trim((string) $slug),
            $packages
        )));
        $packages = array_values(array_filter($packages, static fn (string $slug): bool => $slug !== ''));

        foreach (array_merge([$skeleton], $packages) as $slug) {
            if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
                throw new RuntimeException(sprintf('Invalid package slug [%s].', $slug));
            }
        }

        return [
            'version' => $version,
            'github_org' => $org,
            'branch' => $branch,
            'skeleton' => $skeleton,
            'cache_dir' => $cacheDir,
            'packages' => $packages,
        ];
    }

    /**
     * Expands a leading ~/ in a configured path.
     */
    public static function expandHome(string $path): string
    {
        if ($path !== '~' && !str_starts_with($path, '~/')) {
            return $path;
        }

        $home = getenv('HOME');
        if (!is_string($home) || $home === '') {
            $account = function_exists('posix_getpwuid') ? posix_getpwuid(posix_getuid()) : false;
            $home = is_array($account) ? ($account['dir'] ?? null) : null;
        }

        if (!is_string($home) || $home === '') {
            throw new RuntimeException('Unable to resolve the home directory for [dev] cache_dir.');
        }

        return rtrim($home, '/') . substr($path, 1);
    }

    /**
     * Returns the cache path used for an extracted package.
     *
     * @param array{version:string,github_org:string,branch:string,skeleton:string,cache_dir:string,packages:list<string>} $config
     */
    public static function packagePath(array $config, string $slug): string
    {
        return $config['cache_dir'] . '/' . $config['branch'] . '/' . $slug;
    }

    /**
     * Reports whether an extracted package is already present in the cache.
     *
     * @param array{version:string,github_org:string,branch:string,skeleton:string,cache_dir:string,packages:list<string>} $config
     */
    public static function isCached(array $config, string $slug): bool
    {
        return is_file(self::packagePath($config, $slug) . '/composer.json');
    }

    /**
     * Copies a cached skeleton into a new application directory.
     */
    public static function copyDirectory(string $source, string $destination): void
    {
        if (!is_dir($source)) {
            throw new RuntimeException(sprintf('Missing cached package: %s', $source));
        }

        if (!is_dir($destination) && !mkdir($destination, 0775, true) && !is_dir($destination)) {
            throw new RuntimeException(sprintf('Unable to create directory: %s', $destination));
        }

        $base = rtrim($source, '/');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($base) + 1);
            $target = rtrim($destination, '/') . '/' . $relative;

            if ($item->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
                    throw new RuntimeException(sprintf('Unable to create directory: %s', $target));
                }
                continue;
            }

            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
                throw new RuntimeException(sprintf('Unable to create directory: %s', $parent));
            }

            if ($item->isFile() && !copy($item->getPathname(), $target)) {
                throw new RuntimeException(sprintf('Unable to copy file: %s', $relative));
            }
        }

        self::makeBinariesExecutable($destination);
    }

    /**
     * Replaces skeleton binary stubs with symlinks into vendor/bin.
     */
    public static function relinkBinaries(string $root): void
    {
        foreach (['strukt', 'xhttp'] as $name) {
            $link = rtrim($root, '/') . '/' . $name;
            $target = 'vendor/bin/' . $name;
            $targetPath = rtrim($root, '/') . '/' . $target;

            if (!is_file($targetPath)) {
                continue;
            }

            if (is_link($link) && readlink($link) === $target) {
                continue;
            }

            if (is_link($link) || is_file($link)) {
                if (!unlink($link)) {
                    throw new RuntimeException(sprintf('Unable to replace binary stub: %s', $link));
                }
            }

            if (!symlink($target, $link)) {
                throw new RuntimeException(sprintf('Unable to link binary: %s', $link));
            }
        }
    }

    /**
     * Returns the GitHub repository name for a package slug.
     */
    public static function repositoryName(string $slug): string
    {
        return 'strukt-' . $slug;
    }

    /**
     * Builds the GitHub archive URL for a package slug.
     */
    public static function archiveUrl(string $githubOrg, string $branch, string $slug): string
    {
        return sprintf(
            '%s/%s/archive/refs/heads/%s.zip',
            rtrim($githubOrg, '/'),
            self::repositoryName($slug),
            $branch
        );
    }

    /**
     * Reads a composer.json file.
     *
     * @return array<string, mixed>
     */
    public static function readComposer(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('Missing composer.json: %s', $path));
        }

        $contents = file_get_contents($path);
        $data = $contents === false ? null : json_decode($contents, true);
        if (!is_array($data)) {
            throw new RuntimeException(sprintf('Invalid composer.json: %s', $path));
        }

        return $data;
    }

    /**
     * Writes a composer.json file with stable formatting.
     *
     * @param array<string, mixed> $composer
     */
    public static function writeComposer(string $path, array $composer): void
    {
        $json = json_encode(
            $composer,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($json === false) {
            throw new RuntimeException(sprintf('Unable to encode composer.json: %s', $path));
        }

        if (file_put_contents($path, $json . "\n") === false) {
            throw new RuntimeException(sprintf('Unable to write composer.json: %s', $path));
        }
    }

    /**
     * Rewrites Strukt requirements and the package version to the dev branch.
     *
     * @param array<string, mixed> $composer
     * @return array<string, mixed>
     */
    public static function rewritePackageComposer(array $composer, string $version = 'dev-master'): array
    {
        $composer['version'] = $version;

        foreach (['require', 'require-dev'] as $key) {
            if (!isset($composer[$key]) || !is_array($composer[$key])) {
                continue;
            }

            foreach ($composer[$key] as $name => $constraint) {
                if (is_string($name) && str_starts_with($name, 'strukt/')) {
                    $composer[$key][$name] = $version;
                }
            }
        }

        return $composer;
    }

    /**
     * Adds explicit path repositories and dev stability to the root project.
     *
     * @param array<string, mixed> $composer
     * @param array{version:string,github_org:string,branch:string,skeleton:string,cache_dir:string,packages:list<string>} $config
     * @return array<string, mixed>
     */
    public static function rewriteRootComposer(array $composer, array $config): array
    {
        $composer = self::rewritePackageComposer($composer, $config['version']);
        $repositories = $composer['repositories'] ?? [];
        if (!is_array($repositories)) {
            $repositories = [];
        }

        foreach ($config['packages'] as $slug) {
            $repository = [
                'type' => 'path',
                'url' => self::packagePath($config, $slug),
                'options' => ['symlink' => true],
            ];

            if (array_is_list($repositories)) {
                $repositories[] = $repository;
            } else {
                $repositories['strukt-' . $slug] = $repository;
            }
        }

        $composer['repositories'] = $repositories;
        $composer['minimum-stability'] = 'dev';
        $composer['prefer-stable'] = false;

        return $composer;
    }

    /**
     * Passes the application name to the skeleton's app:make script.
     *
     * @param array<string, mixed> $composer
     * @return array<string, mixed>
     */
    public static function rewritePostCreateScripts(array $composer, string $appName): array
    {
        if (!isset($composer['scripts']['post-create-project-cmd'])) {
            return $composer;
        }

        $scripts = $composer['scripts']['post-create-project-cmd'];
        if (!is_array($scripts)) {
            return $composer;
        }

        foreach ($scripts as $index => $script) {
            if (
                is_string($script)
                && preg_match('/^@php\s+vendor\/bin\/strukt\s+app:make\s*$/', $script)
            ) {
                $scripts[$index] = $script . ' ' . $appName;
            }
        }

        $composer['scripts']['post-create-project-cmd'] = $scripts;

        return $composer;
    }

    /**
     * Seeds the command map needed before app:make can generate the project cfg.
     */
    public static function seedCommandConfig(string $root): void
    {
        $template = rtrim($root, '/') . '/vendor/strukt/framework/templates/cfg/cmd.sgf';
        if (!is_file($template)) {
            return;
        }

        $directory = rtrim($root, '/') . '/cfg';
        $destination = $directory . '/cmd.ini';
        if (is_file($destination)) {
            return;
        }

        $contents = file_get_contents($template);
        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read command template: %s', $template));
        }

        $contents = preg_replace('/\{if\s+[^}]*\}.*?\{\/if\}\s*/s', '', $contents) ?? $contents;

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create directory: %s', $directory));
        }

        if (file_put_contents($destination, $contents) === false) {
            throw new RuntimeException(sprintf('Unable to write command config: %s', $destination));
        }
    }

    /**
     * Downloads a GitHub archive to a local zip file.
     */
    public static function download(string $url, string $destination): void
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: strukt-install\r\nAccept: application/zip\r\n",
                'timeout' => 60,
                'follow_location' => 1,
                'max_redirects' => 5,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $source = @fopen($url, 'rb', false, $context);
        if ($source === false) {
            throw new RuntimeException(sprintf('Unable to download archive: %s', $url));
        }

        $target = @fopen($destination, 'wb');
        if ($target === false) {
            fclose($source);
            throw new RuntimeException(sprintf('Unable to write archive: %s', $destination));
        }

        $copied = stream_copy_to_stream($source, $target);
        fclose($source);
        fclose($target);

        if ($copied === false || $copied === 0) {
            throw new RuntimeException(sprintf('Empty archive downloaded from: %s', $url));
        }
    }

    /**
     * Extracts a GitHub archive, stripping its repository wrapper directory.
     */
    public static function extract(string $zipPath, string $destination, string $slug, string $branch): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException(sprintf('Unable to open archive: %s', $zipPath));
        }

        if (!is_dir($destination) && !mkdir($destination, 0775, true) && !is_dir($destination)) {
            $zip->close();
            throw new RuntimeException(sprintf('Unable to create directory: %s', $destination));
        }

        $root = self::repositoryName($slug) . '-' . $branch;
        $prefix = $root . '/';
        $count = $zip->numFiles;

        try {
            for ($i = 0; $i < $count; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false || $name === '') {
                    continue;
                }

                $name = str_replace('\\', '/', $name);
                if (
                    str_starts_with($name, '/')
                    || preg_match('/^[A-Za-z]:/', $name)
                    || preg_match('/(^|\/)\.\.(\/|$)/', $name)
                ) {
                    throw new RuntimeException(sprintf('Unsafe path in archive: %s', $name));
                }

                if (rtrim($name, '/') === $root) {
                    continue;
                }

                if (!str_starts_with($name, $prefix)) {
                    throw new RuntimeException(sprintf('Unexpected archive root: %s', $name));
                }

                $relative = substr($name, strlen($prefix));
                if ($relative === '') {
                    continue;
                }

                $path = $destination . '/' . $relative;
                if (str_ends_with($name, '/')) {
                    if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
                        throw new RuntimeException(sprintf('Unable to create directory: %s', $path));
                    }
                    continue;
                }

                $parent = dirname($path);
                if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
                    throw new RuntimeException(sprintf('Unable to create directory: %s', $parent));
                }

                $contents = $zip->getFromIndex($i);
                if ($contents === false || file_put_contents($path, $contents) === false) {
                    throw new RuntimeException(sprintf('Unable to extract archive entry: %s', $name));
                }
            }
        } finally {
            $zip->close();
        }

        self::makeBinariesExecutable($destination);
    }

    /**
     * Restores executable permissions for files in bin directories.
     */
    public static function makeBinariesExecutable(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $base = rtrim($directory, '/');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($base) + 1);
            if (preg_match('#(^|/)bin/[^/]+$#', $relative)) {
                @chmod($file->getPathname(), 0755);
            }
        }
    }

    /**
     * Recursively removes a file or directory tree.
     */
    public static function removeDirectory(string $directory): void
    {
        if (is_file($directory) || is_link($directory)) {
            @unlink($directory);

            return;
        }

        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($directory);
    }
}
