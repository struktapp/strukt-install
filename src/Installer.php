<?php

namespace Strukt\Console\Command;

// use Strukt\Fs;
use Strukt\Console\Color;
use Strukt\Console\Input;
use Strukt\Console\Output;
use Strukt\Process;

// use Strukt\Env;
// use Strukt\Fs;
// use Strukt\Process;
// use Strukt\Templator as Tpl;

/**
* new    Install latest Strukt
*
* Usage:
*
*       name <app_name>
*
* Arguments:
*
*       app_name   Application Name
*/
class Installer extends \Strukt\Console\Command{

	public function execute(Input $in, Output $out){

		$name = $in->get("app_name");

		$version = "strukt/strukt:v1.1.5-alpha";
		$command = sprintf("composer create-project %s --prefer-dist %s",
						$version,
						$name);

		Process::switchChannels();
		$ps = Process::run([$command], function($streamOutput){

			echo Color::write("cyan", $streamOutput);
		});
	}
}