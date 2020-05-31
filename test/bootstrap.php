<?php
global $argv;

use PHPUnit\Framework\Error\Deprecated;
use rollun\logger\LifeCycleToken;

error_reporting(E_ERROR | E_WARNING | E_PARSE);
Deprecated::$enabled = false;

// Change to the project root, to simplify resolving paths
chdir(dirname(__DIR__));

$container = require 'config/container.php';
\rollun\dic\InsideConstruct::setContainer($container);

// Init lifecycle token
$lifeCycleToken = LifeCycleToken::generateToken();

if (LifeCycleToken::getAllHeaders() && array_key_exists("LifeCycleToken", LifeCycleToken::getAllHeaders())) {
    $lifeCycleToken->unserialize(LifeCycleToken::getAllHeaders()["LifeCycleToken"]);
}

$container->setService(LifeCycleToken::class, $lifeCycleToken);

if (getenv("APP_ENV") != 'dev') {
    echo "You cannot start test if environment var APP_ENV not set in dev!";
    exit(1);
}
