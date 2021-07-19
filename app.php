<?php

const ROOT = __DIR__;

require ROOT.'/vendor/autoload.php';

use App\Command\UzMonitorCreate;
use App\Command\UzMonitorRun;
use Symfony\Component\Console\Application;

$application = new Application();

$application->add(new UzMonitorCreate());
$application->add(new UzMonitorRun());

$application->run();