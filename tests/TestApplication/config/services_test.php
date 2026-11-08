<?php

declare(strict_types=1);

use Alma\Sylius\Api\EligibilityEndpointFactoryInterface;
use Alma\Sylius\Api\MerchantEndpointFactoryInterface;
use Alma\Sylius\Api\PaymentEndpointFactoryInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return function (ContainerConfigurator $container) {
    $env = $container->env();
    if (null !== $env && str_starts_with($env, 'test')) {
        $container->import('../../../vendor/sylius/sylius/src/Sylius/Behat/Resources/config/services.xml');
        $container->import('@AlmaSyliusPlugin/tests/Behat/Resources/services.xml');

        // The plugin's real endpoint factories, reachable by the tests: Alma's backend is doubled
        // by the mock server of tests/MockAlma, reached through ALMA_API_URL — nothing is replaced here.
        $services = $container->services();
        $services->alias('test.alma.merchant_endpoint_factory', MerchantEndpointFactoryInterface::class)->public();
        $services->alias('test.alma.eligibility_endpoint_factory', EligibilityEndpointFactoryInterface::class)->public();
        $services->alias('test.alma.payment_endpoint_factory', PaymentEndpointFactoryInterface::class)->public();
    }
};
