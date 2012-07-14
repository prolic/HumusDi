<?php
chdir(dirname(__DIR__));
ini_set('display_errors', 1);

include 'vendor/autoload.php';

echo "\nreading config files...\n";

/*
 * Sample implementation of merging 3 given config files
 * change that for your needs
 */

/*
$alias = new Zend\Config\Config(include 'application/configs/di/alias.php', true);

$zend = new Zend\Config\Config(include 'application/configs/di/zend.php', true);

$humus = new Zend\Config\Config(include 'application/configs/di/humus.php', true);

$config = $alias
    ->merge($zend)
    ->merge($humus)
    ->toArray();

*/

echo "generating application context...\n";
$generator = new \Humus\Di\ServiceLocator\Generator($config);
$generator->setNamespace('Application');
$generator->setContainerClass('Context');
$file = $generator->getCodeGenerator();
$file->setFilename('application/Context.php');
$file->write();

echo "\ndone\n";

exit();
