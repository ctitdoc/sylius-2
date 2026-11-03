<?php

declare(strict_types=1);

namespace Alma\Sylius\Api;

use Alma\Client\Application\ClientConfiguration;
use Alma\Client\Domain\ValueObject\Environment;
use Composer\InstalledVersions;
use Sylius\Bundle\CoreBundle\SyliusCoreBundle;

/**
 * Builds the alma-php-client configuration shared by every endpoint factory,
 * carrying the User-Agent components that identify this plugin to the Alma
 * API (`Alma for Sylius2/<v>; Sylius/<v>; Alma for PHP/<v>; PHP/<v>`).
 */
final class ClientConfigurationFactory
{
    private const PLUGIN_PACKAGE = 'alma/sylius-2-payment';

    /**
     * @param string|null $customApiUrl when set, every call — whatever the configured mode — goes to
     *                                  this base URL instead of Alma's live or sandbox API. Meant for
     *                                  development: a local Alma simulator, a tunnel, a staging API.
     *                                  Bound to the ALMA_API_URL environment variable in services.yaml.
     */
    public function __construct(
        private readonly ?string $customApiUrl = null,
    ) {
    }

    public function make(string $apiKey, string $mode): ClientConfiguration
    {
        $environment = $this->customApiUrl !== null && $this->customApiUrl !== ''
            ? new Environment(Environment::CUSTOM_MODE, $this->customApiUrl)
            : new Environment($mode === 'live' ? Environment::LIVE_MODE : Environment::TEST_MODE);
        $configuration = new ClientConfiguration($apiKey, $environment);
        $configuration->addUserAgentComponent('Sylius', SyliusCoreBundle::VERSION);
        $configuration->addUserAgentComponent('Alma for Sylius2', $this->pluginVersion());

        return $configuration;
    }

    private function pluginVersion(): string
    {
        if (!InstalledVersions::isInstalled(self::PLUGIN_PACKAGE)) {
            return 'dev';
        }

        return InstalledVersions::getPrettyVersion(self::PLUGIN_PACKAGE) ?? 'dev';
    }
}
