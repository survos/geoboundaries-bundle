#!/usr/bin/env php
<?php

declare(strict_types=1);

use Survos\GeoBoundariesAdmin\AdminConfig;
use Survos\GeoBoundariesAdmin\Command\BuildCommand;
use Survos\GeoBoundariesAdmin\Command\DownloadCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

$adminDir = __DIR__;
$envPath = $adminDir.'/.env';
if (!is_file($envPath)) {
    throw new RuntimeException(sprintf('Missing required env file: %s', $envPath));
}

$dotenv = new Dotenv();
$dotenv->load($envPath);
$localEnvPath = $adminDir.'/.env.local';
if (is_file($localEnvPath)) {
    $dotenv->load($localEnvPath);
}

$config = AdminConfig::fromAdminDir($adminDir);
$application = new Application('geoboundaries-admin', '0.1.0');
$application->addCommands([
    new DownloadCommand($config),
    new BuildCommand($config),
]);
$application->run();
