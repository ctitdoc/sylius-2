<?php

declare(strict_types=1);

namespace Tests\Alma\Sylius\TestApplication\Alma;

use Alma\Client\Application\Endpoint\EligibilityEndpoint;
use Alma\Sylius\Api\ClientConfigurationFactory;
use Alma\Sylius\Api\EligibilityEndpointFactoryInterface;

/** The plugin's factory, in the Test Application: the real endpoint of the client over the in-memory Alma. */
final class InMemoryEligibilityEndpointFactory implements EligibilityEndpointFactoryInterface
{
    public function __construct(
        private readonly InMemoryAlma $alma,
        private readonly ClientConfigurationFactory $configurationFactory,
    ) {
    }

    public function make(string $apiKey, string $mode): EligibilityEndpoint
    {
        return new EligibilityEndpoint(new InMemoryAlmaHttpClient($this->alma, $this->configurationFactory->make($apiKey, $mode)));
    }
}
