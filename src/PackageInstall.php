<?php

namespace Strukt\Console\Command;

use Strukt\Console\Color;
use Strukt\Console\Input;
use Strukt\Console\Output;
use Strukt\Process;

/**
* package:install    Install Strukt Module
*
* Usage:
*
*       package:install <module> [<app_name>] [--publish]
*
* Arguments:
*
*       module    Module Name
*       app_name  Application Name Folder
*
* Options:
*
*      --publish -p   Publish package and requirements after installation
*/
class PackageInstall extends \Strukt\Console\Command{

	public function execute(Input $in, Output $out){

		$name = $in->get("app_name");
		$inputs = $in->getInputs();
		if(!array_key_exists("app_name", $inputs))
			$name = ".";
		
		$module = $in->get("module");
		if(!preg_match("/^pkg\-/", $module))
			$module = sprintf("pkg-%s", $module);

		$setting = parse_ini_file(__DIR__."/../strukt.ini");
		if(!array_key_exists($module, $setting["modules"]))
			throw new \Exception(sprintf("Module [%s] does not exist!", $module));

		\Strukt\Fs::rm(sprintf("%s/composer.lock", $name));

		$cmd = sprintf("composer require strukt/%s:%s --working-dir=%s", 
						$module,
						$setting["modules"][$module],
						$name);

		Process::switchChannels();
		$ps = Process::run([$cmd], function($streamOutput){

			echo Color::write("cyan", $streamOutput);
		});

		$reqs = [];
		$isPublished = false;
		if(array_key_exists("publish", $in->getInputs())){

			if(chdir($name)){

				$reqKey = sprintf("req.%s", $module);
				if(array_key_exists($reqKey, $setting))
					foreach($setting[$reqKey] as $req){

						$reqs[] = $req;
						exec(sprintf("php ./xcli package:publish %s", $req));
					}

				exec(sprintf("php ./xcli package:publish %s", $module));
			}

			$isPublished = true;
		}

		if($isPublished){

			$reqs[] = $module;
			foreach($reqs as $req){

				$prvKey = sprintf("prv.%s", $req);
				if(array_key_exists($prvKey, $setting)){

					$providers = $setting[$prvKey];
					foreach($providers as $provider)
						exec(sprintf("php ./xcli sys:util enable provider %s", $provider));
				}

				$mdlKey = sprintf("mdl.%s", $req);
				if(array_key_exists($mdlKey, $setting)){

					$middlewares = $setting[$mdlKey];
					foreach($middlewares as $middleware)
						exec(sprintf("php ./xcli sys:util enable middleware %s", $middleware));
				}

				$cmdKey = sprintf("cmd.%s", $req);
				if(array_key_exists($cmdKey, $setting))
					if($setting[$cmdKey])
						exec(sprintf("php ./xcli sys:util enable command %s", $req));
			}
		}
	}
}