<?php

declare(strict_types=1);

$own = __DIR__ . '/../vendor/autoload.php';
$shop = getenv('SYLIUS_AUTOLOAD') ?: '/srv/sylius/vendor/autoload.php';
$autoload = is_file($own) ? $own : $shop;
if (!is_file($autoload)) {
    fwrite(STDERR, "No autoloader: install the plugin's dependencies, or set SYLIUS_AUTOLOAD to the shop's vendor/autoload.php\n");
    exit(1);
}
require $autoload;
