<?php

declare (strict_types = 1);

namespace Strukt\Installer\Command;

use Strukt\Console\Runtime\CommandInterface;

/**
 * @command new
 * @description Install latest Strukt
 * @usage new <app_name>
 *
 * @argument app_name
 * @description Application Name
 */
final class Installer implements CommandInterface
{
    public function execute(array $arguments, array $options): int
    {
        $appName = $arguments['app_name'] ?? null;
        if (empty($appName)) {
            throw new \Exception('Argument [app_name] is required!');
        }

        $setting = parse_ini_file(__DIR__ . '/../../../../strukt.ini');

        $package = $setting['package']['main'];
        $version = $setting['package']['version'];
        $command = sprintf(
            'composer create-project %s:%s --prefer-dist --stability=dev %s',
            $package,
            $version,
            $appName
        );

        echo sprintf("%s\n", $command);

        \switchChannels();

        $ps = \process([$command], function ($streamOutput) {
            if ($streamOutput !== null) {
                echo $streamOutput;
            }
        });

        if (chdir($appName)) {
            if (! preg_match('/^[A-Za-z0-9\_\-]*$/', $appName)) {
                new \Exception(sprintf('Invalid app_name:[%s]', $appName));
            }

            $appName = str_replace(['-', '.'], '_', $appName);
            $appName = trim($appName, '*-_');

            // exec(sprintf('php bin/strukt app:make %s', $appName));
            // exec('php bin/strukt app:reload');
            // exec('php bin/strukt cache:clear');
            // exec('php bin/strukt cache:make');
        }

        return 0;
    }
}
