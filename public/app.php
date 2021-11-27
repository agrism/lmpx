<?php

declare(strict_types=1);

namespace App;

use App\App\Application;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

require __DIR__ . '/../vendor/autoload.php';
echo '<pre>';
require __DIR__.'/../src/Service/source.php';


$container = new ContainerBuilder();

$loader = new YamlFileLoader($container, new FileLocator());
try {
    $loader->load(__DIR__ . '/../config/services.yaml');
} catch (\Exception $e) {
    echo $e->getMessage();
}

// Compile container
$container->compile();

// Start the console application.
try {
    exit($container->get(Application::class)->run());
} catch (\Exception $e) {
    echo $e->getMessage();
}
