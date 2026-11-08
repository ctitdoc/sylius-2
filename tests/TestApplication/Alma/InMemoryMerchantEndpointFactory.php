<?php

declare(strict_types=1);

namespace Tests\Alma\Sylius\TestApplication\Alma;

use Alma\Client\Application\Endpoint\MerchantEndpoint;
use Alma\Sylius\Api\ClientConfigurationFactory;
use Alma\Sylius\Api\MerchantEndpointFactoryInterface;

/** The plugin's factory, in the Test Application: the real endpoint of the client over the in-memory Alma. */
final class InMemoryMerchantEndpointFactory implements MerchantEndpointFactoryInterface
{
    public function __construct(
        private readonly InMemoryAlma $alma,
        private readonly ClientConfigurationFactory $configurationFactory,
    ) {
    }

    public function make(string $apiKey, string $mode): MerchantEndpoint
    {
        return new MerchantEndpoint(new InMemoryAlmaHttpClient($this->alma, $this->configurationFactory->make($apiKey, $mode)));
    }
}
