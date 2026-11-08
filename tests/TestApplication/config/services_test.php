<?php

declare(strict_types=1);

use Alma\Sylius\Api\EligibilityEndpointFactoryInterface;
use Alma\Sylius\Api\MerchantEndpointFactoryInterface;
use Alma\Sylius\Api\PaymentEndpointFactoryInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Tests\Alma\Sylius\TestApplication\Alma\InMemoryAlma;
use Tests\Alma\Sylius\TestApplication\Alma\InMemoryEligibilityEndpointFactory;
use Tests\Alma\Sylius\TestApplication\Alma\InMemoryMerchantEndpointFactory;
use Tests\Alma\Sylius\TestApplication\Alma\InMemoryPaymentEndpointFactory;

return function (ContainerConfigurator $container) {
    $env = $container->env();
    if (null !== $env && str_starts_with($env, 'test')) {
        $container->import('../../../vendor/sylius/sylius/src/Sylius/Behat/Resources/config/services.xml');
        $container->import('@AlmaSyliusPlugin/tests/Behat/Resources/services.xml');

        // Alma's backend, which no test environment can host, doubled in memory under the real client:
        // the plugin's three endpoint factories build the client's endpoints over InMemoryAlma.
        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->set(InMemoryAlma::class)->public();
        $services->set(InMemoryMerchantEndpointFactory::class);
        $services->set(InMemoryEligibilityEndpointFactory::class);
        $services->set(InMemoryPaymentEndpointFactory::class);
        $services->alias(MerchantEndpointFactoryInterface::class, InMemoryMerchantEndpointFactory::class)->public();
        $services->alias(EligibilityEndpointFactoryInterface::class, InMemoryEligibilityEndpointFactory::class)->public();
        $services->alias(PaymentEndpointFactoryInterface::class, InMemoryPaymentEndpointFactory::class)->public();
    }
};
