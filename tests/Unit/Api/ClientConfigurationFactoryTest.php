<?php

declare(strict_types=1);

namespace Alma\Sylius\Tests\Unit\Api;

use Alma\Client\Domain\ValueObject\Environment;
use Alma\Sylius\Api\ClientConfigurationFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Brick "API access" (sylius-plugin-commons.md#api-access): the environment follows the
 * configured mode of the [[merchant-configuration]], unless ALMA_API_URL points the plugin at a
 * custom base URL — development only. Issue alma-case-study#2.
 */
final class ClientConfigurationFactoryTest extends TestCase
{
    public function testTestModeReachesTheSandbox(): void
    {
        $environment = (new ClientConfigurationFactory())->make('sk_test_x', 'test')->getEnvironment();

        self::assertTrue($environment->isTestMode());
        self::assertSame(Environment::SANDBOX_API_URL, (string) $environment->getBaseUri());
    }

    public function testLiveModeReachesAlma(): void
    {
        $environment = (new ClientConfigurationFactory())->make('sk_live_x', 'live')->getEnvironment();

        self::assertTrue($environment->isLiveMode());
        self::assertSame(Environment::LIVE_API_URL, (string) $environment->getBaseUri());
    }

    #[DataProvider('modes')]
    public function testACustomApiUrlWinsWhateverTheMode(string $mode): void
    {
        $environment = (new ClientConfigurationFactory('http://alma-mock:8000/'))->make('sk_test_x', $mode)->getEnvironment();

        self::assertTrue($environment->isCustomMode(), "mode $mode");
        self::assertSame('http://alma-mock:8000', (string) $environment->getBaseUri(), 'trailing slash dropped by the client');
    }

    #[DataProvider('modes')]
    public function testAnEmptyCustomApiUrlChangesNothing(string $mode): void
    {
        $withEmpty = (new ClientConfigurationFactory(''))->make('sk_test_x', $mode)->getEnvironment();
        $withNull = (new ClientConfigurationFactory(null))->make('sk_test_x', $mode)->getEnvironment();
        $default = (new ClientConfigurationFactory())->make('sk_test_x', $mode)->getEnvironment();

        self::assertSame($default->getMode(), $withEmpty->getMode());
        self::assertSame($default->getMode(), $withNull->getMode());
        self::assertFalse($withEmpty->isCustomMode());
    }

    public function testTheUserAgentNamesThePluginAndSylius(): void
    {
        $userAgent = (new ClientConfigurationFactory())->make('sk_test_x', 'test')->getUserAgentString();

        self::assertStringContainsString('Alma for Sylius2/', $userAgent);
        self::assertStringContainsString('Sylius/', $userAgent);
    }

    /** @return iterable<string, array{string}> */
    public static function modes(): iterable
    {
        yield 'test' => ['test'];
        yield 'live' => ['live'];
    }
}
