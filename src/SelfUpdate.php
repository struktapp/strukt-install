<?php

namespace Strukt\Console\Command;

use Strukt\Console\Color;
use Strukt\Console\Input;
use Strukt\Console\Output;
use Strukt\Process;
use Dotenv\Dotenv;

/**
* selfupdate    Update Strukt Installer
*/
class SelfUpdate extends \Strukt\Console\Command{

	public function __construct(){

		$dotenv = Dotenv::createImmutable(__DIR__."/../");
		$dotenv->load();
	}

	public function execute(Input $in, Output $out){

		$composer_home = realpath(__DIR__."/../../../");

		$cmd = sprintf("composer update strukt/install --working-dir=%s", $composer_home);

		Process::switchChannels();
		$ps = Process::run([$cmd], function($streamOutput){

			echo Color::write("cyan", $streamOutput);
		});
	}
}