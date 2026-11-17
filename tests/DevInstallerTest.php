<?php

declare (strict_types = 1);

require __DIR__ . '/../vendor/autoload.php';

use Strukt\Installer\DevInstaller;

$tests = [];

$test = static function (string $name, callable $callback) use (&$tests): void {
    $tests[$name] = $callback;
};

$assertSame = static function (mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%s (expected %s, got %s)',
            $message,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
};

$assertTrue = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$test('normalizes the shipped dev configuration', static function () use ($assertSame, $assertTrue): void {
    $setting = parse_ini_file(__DIR__ . '/../strukt.ini', true);
    $config = DevInstaller::normalizeConfig($setting);

    $assertSame('dev-master', $config['version'], 'version');
    $assertSame('https://github.com/struktapp', $config['github_org'], 'github_org');
    $assertSame('master', $config['branch'], 'branch');
    $assertSame('strukt', $config['skeleton'], 'skeleton');
    $assertSame(
        rtrim((string) getenv('HOME'), '/') . '/.cache/strukt',
        $config['cache_dir'],
        'cache_dir'
    );
    $assertTrue(in_array('framework', $config['packages'], true), 'framework package is listed');
    $assertTrue(!in_array('strukt', $config['packages'], true), 'skeleton is not a path package');
});

$test('builds GitHub archive URLs', static function () use ($assertSame): void {
    $assertSame(
        'https://github.com/struktapp/strukt-orm/archive/refs/heads/master.zip',
        DevInstaller::archiveUrl('https://github.com/struktapp/', 'master', 'orm'),
        'archive URL'
    );
});

$test('rewrites Strukt requirements only', static function () use ($assertSame): void {
    $composer = [
        'name' => 'strukt/strukt',
        'version' => '1.2.0',
        'require' => [
            'strukt/framework' => '^1.2',
            'psr/log' => '^3.0',
        ],
        'require-dev' => [
            'strukt/console' => 'v2.0.0',
            'phpunit/phpunit' => '^10.0',
        ],
    ];

    $result = DevInstaller::rewritePackageComposer($composer, 'dev-master');

    $assertSame('dev-master', $result['version'], 'version');
    $assertSame('dev-master', $result['require']['strukt/framework'], 'framework constraint');
    $assertSame('^3.0', $result['require']['psr/log'], 'third-party constraint');
    $assertSame('dev-master', $result['require-dev']['strukt/console'], 'console constraint');
    $assertSame('^10.0', $result['require-dev']['phpunit/phpunit'], 'dev third-party constraint');
});

$test('adds path repositories and dev stability', static function () use ($assertSame): void {
    $config = DevInstaller::normalizeConfig([
        'dev' => [
            'version' => 'dev-master',
            'github_org' => 'https://github.com/struktapp',
            'branch' => 'master',
            'skeleton' => 'strukt',
            'cache_dir' => '/tmp/strukt-cache',
            'packages' => ['base', 'framework'],
        ],
    ]);

    $result = DevInstaller::rewriteRootComposer([
        'name' => 'example/app',
        'version' => '1.2.0',
        'require' => ['strukt/framework' => '^1.2'],
    ], $config);

    $assertSame('dev', $result['minimum-stability'], 'minimum-stability');
    $assertSame(false, $result['prefer-stable'], 'prefer-stable');
    $assertSame('dev-master', $result['require']['strukt/framework'], 'framework constraint');
    $assertSame([
        'type' => 'path',
        'url' => '/tmp/strukt-cache/master/base',
        'options' => ['symlink' => true],
    ], $result['repositories'][0], 'first path repository');
    $assertSame('/tmp/strukt-cache/master/framework', $result['repositories'][1]['url'], 'second path repository');
});

$test('extracts archives without the repository wrapper', static function () use ($assertSame, $assertTrue): void {
    $temp = sys_get_temp_dir() . '/strukt-dev-master-test-' . bin2hex(random_bytes(6));
    mkdir($temp, 0775, true);
    $zipPath = $temp . '/strukt-demo-master.zip';
    $destination = $temp . '/app';

    try {
        $zip = new \ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('strukt-demo-master/composer.json', '{"name":"strukt/demo"}');
        $zip->addFromString('strukt-demo-master/bin/demo', "#!/usr/bin/env php\n");
        $zip->addFromString('strukt-demo-master/src/Demo.php', "<?php\n");
        $zip->close();

        DevInstaller::extract($zipPath, $destination, 'demo', 'master');

        $assertSame(
            '{"name":"strukt/demo"}',
            file_get_contents($destination . '/composer.json'),
            'stripped composer.json'
        );
        $assertTrue(is_file($destination . '/src/Demo.php'), 'nested source file');
        $assertTrue((fileperms($destination . '/bin/demo') & 0111) !== 0, 'bin file is executable');
        $assertTrue(!file_exists($destination . '/strukt-demo-master'), 'wrapper directory removed');
    } finally {
        DevInstaller::removeDirectory($temp);
    }
});

$test('passes the app name to app:make', static function () use ($assertSame): void {
    $result = DevInstaller::rewritePostCreateScripts([
        'scripts' => [
            'post-create-project-cmd' => [
                '@php vendor/bin/strukt app:make',
                '@php vendor/bin/strukt app:reload',
            ],
        ],
    ], 'payroll');

    $assertSame('@php vendor/bin/strukt app:make payroll', $result['scripts']['post-create-project-cmd'][0], 'app:make script');
    $assertSame('@php vendor/bin/strukt app:reload', $result['scripts']['post-create-project-cmd'][1], 'app:reload script');
});

$test('seeds the command config from the framework template', static function () use ($assertSame, $assertTrue): void {
    $temp = sys_get_temp_dir() . '/strukt-cmd-config-test-' . bin2hex(random_bytes(6));
    $templateDir = $temp . '/vendor/strukt/framework/templates/cfg';
    mkdir($templateDir, 0775, true);
    file_put_contents(
        $templateDir . '/cmd.sgf',
        "[main]\ntitle = Strukt\ncmd[] = app:make\n{if bundle_db}cmd[] = db:create{/if}\n"
    );

    try {
        DevInstaller::seedCommandConfig($temp);

        $contents = (string) file_get_contents($temp . '/cfg/cmd.ini');
        $assertSame("[main]\ntitle = Strukt\ncmd[] = app:make\n", $contents, 'rendered command config');
        $assertTrue(!str_contains($contents, '{if'), 'conditional removed');
    } finally {
        DevInstaller::removeDirectory($temp);
    }
});

$test('detects cached packages', static function () use ($assertSame, $assertTrue): void {
    $config = DevInstaller::normalizeConfig([
        'dev' => [
            'version' => 'dev-master',
            'github_org' => 'https://github.com/struktapp',
            'branch' => 'master',
            'skeleton' => 'strukt',
            'cache_dir' => '/tmp/strukt-cache-test',
            'packages' => ['framework'],
        ],
    ]);

    $path = $config['cache_dir'] . '/master/framework';
    DevInstaller::removeDirectory($config['cache_dir']);
    mkdir($path, 0775, true);
    try {
        $assertTrue(!DevInstaller::isCached($config, 'framework'), 'empty directory is not cached');
        file_put_contents($path . '/composer.json', '{"name":"strukt/framework"}');
        $assertTrue(DevInstaller::isCached($config, 'framework'), 'composer.json marks a cached package');
        $assertSame(false, DevInstaller::isCached($config, 'orm'), 'other package is not cached');
    } finally {
        DevInstaller::removeDirectory($config['cache_dir']);
    }
});

$test('copies a cached skeleton into the application', static function () use ($assertSame, $assertTrue): void {
    $temp = sys_get_temp_dir() . '/strukt-copy-test-' . bin2hex(random_bytes(6));
    $source = $temp . '/skeleton';
    $destination = $temp . '/app';
    mkdir($source . '/bin', 0775, true);
    mkdir($source . '/docs', 0775, true);
    file_put_contents($source . '/composer.json', '{"name":"strukt/strukt"}');
    file_put_contents($source . '/bin/strukt', "#!/usr/bin/env php\n");
    file_put_contents($source . '/docs/README.md', "# App\n");

    try {
        DevInstaller::copyDirectory($source, $destination);

        $assertSame('{"name":"strukt/strukt"}', file_get_contents($destination . '/composer.json'), 'copied composer.json');
        $assertTrue(is_file($destination . '/docs/README.md'), 'copied nested file');
        $assertTrue((fileperms($destination . '/bin/strukt') & 0111) !== 0, 'copied bin is executable');
    } finally {
        DevInstaller::removeDirectory($temp);
    }
});

$test('relinks skeleton binary stubs', static function () use ($assertSame, $assertTrue): void {
    $temp = sys_get_temp_dir() . '/strukt-relink-test-' . bin2hex(random_bytes(6));
    mkdir($temp . '/vendor/bin', 0775, true);
    file_put_contents($temp . '/vendor/bin/strukt', "#!/usr/bin/env php\n");
    file_put_contents($temp . '/vendor/bin/xhttp', "#!/usr/bin/env php\n");
    file_put_contents($temp . '/strukt', "vendor/bin/strukt\n");
    file_put_contents($temp . '/xhttp', "vendor/bin/xhttp\n");

    try {
        DevInstaller::relinkBinaries($temp);

        $assertTrue(is_link($temp . '/strukt'), 'strukt is a symlink');
        $assertSame('vendor/bin/strukt', readlink($temp . '/strukt'), 'strukt link target');
        $assertTrue(is_link($temp . '/xhttp'), 'xhttp is a symlink');
        $assertSame('vendor/bin/xhttp', readlink($temp . '/xhttp'), 'xhttp link target');

        DevInstaller::relinkBinaries($temp);
        $assertTrue(is_link($temp . '/strukt'), 'relink is idempotent');
    } finally {
        DevInstaller::removeDirectory($temp);
    }
});

$failures = 0;
foreach ($tests as $name => $callback) {
    try {
        $callback();
        echo sprintf("PASS %s\n", $name);
    } catch (Throwable $exception) {
        $failures++;
        echo sprintf("FAIL %s: %s\n", $name, $exception->getMessage());
    }
}

echo sprintf("\n%d passed, %d failed\n", count($tests) - $failures, $failures);
exit($failures === 0 ? 0 : 1);
