<?php

namespace Strukt\Installer\Command;

use Strukt\Console\Color;
use Strukt\Console\Input;
use Strukt\Console\Output;
use Strukt\Process;

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

		$setting = parse_ini_file(__DIR__."/../../../../strukt.ini");

		$package = $setting["package"]["main"];
		$version = $setting["package"]["version"];
		$command = sprintf("composer create-project %s:%s --prefer-dist --stability=dev %s",
						$package,
						$version,
						$app_name);

		echo(sprintf("%s\n", $command));

		switchChannels();
		$ps = process([$command], function($streamOutput) use($app_name){

			echo color("cyan", $streamOutput);

			if(is_null($streamOutput))//when finished processing
				if(chdir($app_name)){

					if(!preg_match("/^[A-Za-z0-9\_\-]*$/", $app_name))
						new \Exception(sprintf("Invalid app_name:[%s]", $app_name));

					$app_name = str_replace(["-","."], "_", $app_name);
					$app_name = trim($app_name, "*-_");

					exec(sprintf("php xcli app:make %s", $app_name));
					exec("php xcli app:reload");
				}
		});			
	}
}