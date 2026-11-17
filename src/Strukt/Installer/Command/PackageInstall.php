<?php

declare (strict_types = 1);

namespace Strukt\Installer\Command;

use Strukt\Console\Runtime\CommandInterface;

/**
 * @command add
 * @description Install Strukt Module
 * @usage add <module> [<app_name>] [--publish]
 *
 * @argument module
 * @description Module Name
 *
 * @argument app_name
 * @description Application Name Folder
 *
 * @option publish
 * @flag
 * @short p
 * @description Publish package and requirements after installation
 */
final class PackageInstall implements CommandInterface
{
    public function execute(array $arguments, array $options): int
    {
        $name = array_key_exists('app_name', $arguments) && $arguments['app_name'] !== null
            ? $arguments['app_name']
            : '.';

        $module = $arguments['module'] ?? null;
        if (! preg_match('/^pkg\-/', (string) $module)) {
            $module = sprintf('pkg-%s', $module);
        }

        $setting = parse_ini_file(__DIR__ . '/../../../../strukt.ini');
        if (! array_key_exists($module, $setting['modules'])) {
            throw new \Exception(sprintf('Module [%s] does not exist!', $module));
        }

        $lock = sprintf('%s/composer.lock', $name);
        if (is_file($lock)) {
            unlink($lock);
        }

        $cmd = sprintf(
            'composer require strukt/%s:%s --working-dir=%s',
            $module,
            $setting['modules'][$module],
            $name
        );

        \switchChannels();

        $ps = \process([$cmd], function ($streamOutput) {
            if ($streamOutput !== null) {
                echo $streamOutput;
            }
        });

        // $reqs = [];
        // $isPublished = false;
        // if (!empty($options['publish'])) {
        //     if (chdir($name)) {
        //         $reqKey = sprintf('req.%s', $module);
        //         if (array_key_exists($reqKey, $setting)) {
        //             foreach ($setting[$reqKey] as $req) {
        //                 $reqs[] = $req;
        //                 exec(sprintf('php bin/strukt package:publish %s', $req));
        //             }
        //         }

        //         exec(sprintf('php bin/strukt package:publish %s', $module));
        //     }

        //     $isPublished = true;
        // }

        // if ($isPublished) {
        //     $reqs[] = $module;
        //     foreach ($reqs as $req) {
        //         $prvKey = sprintf('prv.%s', $req);
        //         if (array_key_exists($prvKey, $setting)) {
        //             $providers = $setting[$prvKey];
        //             foreach ($providers as $provider) {
        //                 exec(sprintf('php bin/strukt sys:util enable provider %s', $provider));
        //             }
        //         }

        //         $mdlKey = sprintf('mdl.%s', $req);
        //         if (array_key_exists($mdlKey, $setting)) {
        //             $middlewares = $setting[$mdlKey];
        //             foreach ($middlewares as $middleware) {
        //                 exec(sprintf('php bin/strukt sys:util enable middleware %s', $middleware));
        //             }
        //         }

        //         $cmdKey = sprintf('cmd.%s', $req);
        //         if (array_key_exists($cmdKey, $setting)) {
        //             exec(sprintf('php bin/strukt sys:util enable command %s', $req));
        //         }
        //     }
        // }

        // exec('php bin/strukt cache:clear');
        // exec('php bin/strukt cache:make');

        return 0;
    }
}
