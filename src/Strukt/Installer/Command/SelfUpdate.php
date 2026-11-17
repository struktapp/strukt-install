<?php

declare(strict_types=1);

namespace Strukt\Installer\Command;

use Strukt\Console\Runtime\CommandInterface;

/**
 * @command update:me
 * @description Update Strukt Installer
 */
final class SelfUpdate implements CommandInterface
{
    public function execute(array $arguments, array $options): int
    {
        $composerHome = realpath(__DIR__ . '/../../../../../../../');

        $cmd = sprintf('composer update strukt/install --working-dir=%s', $composerHome);

        \switchChannels();

        $ps = \process([$cmd], function ($streamOutput) {
            if ($streamOutput !== null) {
                echo $streamOutput;
            }
        });

        return 0;
    }
}