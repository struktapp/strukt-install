<?php

namespace Strukt\Console\Command;

use Strukt\Console\Color;
use Strukt\Console\Input;
use Strukt\Console\Output;
use Strukt\Process;
use Dotenv\Dotenv;

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

		$app_name = $in->get("app_name");
		if(empty($app_name))
			throw new \Exception("Argument [app_name] is required!");

		$setting = parse_ini_file(__DIR__."/../strukt.ini");

		$package = $setting["package"]["main"];
		$version = $setting["package"]["version"];
		$command = sprintf("composer create-project %s:%s --prefer-dist %s",
						$package,
						$version,
						$app_name);

		Process::switchChannels();
		$ps = Process::run([$command], function($streamOutput){

			echo Color::write("cyan", $streamOutput);
		});

		if(chdir($app_name)){

			exec(sprintf("php xcli app:make %s", $app_name));
			exec("php xcli app:reload");
		}
	}
}