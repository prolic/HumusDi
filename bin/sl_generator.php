<?php
chdir(dirname(__DIR__));
ini_set('display_errors', 1);
define('APPLICATION_PATH_MD5', md5(getcwd()));

include 'application/autoload.php';

$rules = array(
    'help|h'          => 'Get usage message',
    'environment|e=w' => 'Application environment (production, staging, development, testing)'
);

try {
    $opts = new Zend_Console_Getopt($rules);
    $opts->parse();
} catch (Zend_Console_Getopt_Exception $e) {
    echo $e->getUsageMessage();
    exit();
}

if ($env = $opts->getOption('e')) {
    echo "\nreading config files...\n";
    define('APPLICATION_ENV', $env);

    $alias = new Zend\Config\Config(include 'application/configs/di/alias.php', true);

    $zend = new Zend\Config\Config(include 'application/configs/di/zend.php', true);

    $humus = new Zend\Config\Config(include 'application/configs/di/humus.php', true);

    $gedmo = new Zend\Config\Config(include 'application/configs/di/gedmo.php', true);

    $doctrine = new Zend\Config\Config(include 'application/configs/di/doctrine.php', true);

    $app = new Zend\Config\Config(include 'application/configs/di/application.php', true);

    $controller = new Zend\Config\Config(include 'application/configs/di/controller.php', true);

    $acl = new Zend\Config\Config(include 'application/configs/di/acl.php', true);

    $settings = new Zend\Config\Config(include 'application/configs/di/settings.' . $env . '.php', true);

    $config = $alias
        ->merge($zend)
        ->merge($humus)
        ->merge($gedmo)
        ->merge($doctrine)
        ->merge($app)
        ->merge($acl)
        ->merge($controller)
        ->merge($settings)  // must be last
        ->toArray();

    echo "generating application context...\n";
    $generator = new \Humus\Di\ServiceLocator\Generator($config);
    $generator->setNamespace('Application');
    $generator->setContainerClass('Context');
    $file = $generator->getCodeGenerator();
    $file->setFilename('application/Context.php');
    $file->write();

    echo "\ndone\n";

    exit();
} else {
    echo $opts->getUsageMessage();
    exit();
}
