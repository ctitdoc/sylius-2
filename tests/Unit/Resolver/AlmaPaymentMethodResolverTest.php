<?php

declare(strict_types=1);

namespace Tests\Alma\Sylius\Unit\Resolver;

use Alma\Sylius\Entity\AlmaConfiguration;
use Alma\Sylius\Resolver\AlmaPaymentMethodResolver;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Repository\PaymentMethodRepositoryInterface;

/**
 * Characterisation of the configuration resolver ([[merchant-configuration]],
 * [[one-alma-configuration-per-shop]]): the first payment method whose gateway factory is `alma`
 * gives the Merchant's configuration; the API key of a mode; a shop without Alma gets defaults.
 * Pruned on 7 November 2026 (practices, "what a unit test keeps"): the read-once mechanism.
 *
 * @implementation [[sylius-plugin-commons.md#configuration-resolver]]
 */
final class AlmaPaymentMethodResolverTest extends TestCase
{
    public function testTheFirstAlmaMethodGivesTheConfiguration(): void
    {
        $resolver = new AlmaPaymentMethodResolver($this->repositoryWith(
            $this->method('offline', []),
            $this->method('alma', ['api_mode' => 'live', 'api_key_live' => 'sk_live_a', 'api_key_test' => 'sk_test_a', 'merchant_id' => 'merchant_a', 'fee_plans' => ['general_3_0_0' => ['allowed' => true]], 'display_texts' => ['fr_FR' => ['title' => ['text' => 'Alma']]]]),
            $this->method('alma', ['api_mode' => 'test', 'api_key_test' => 'sk_test_b']),
        ));

        $configuration = $resolver->getConfiguration();
        self::assertSame('live', $configuration->getApiMode());
        self::assertSame('sk_live_a', $configuration->getApiKeyLive());
        self::assertSame('merchant_a', $configuration->getMerchantId());
        self::assertSame(['general_3_0_0' => ['allowed' => true]], $configuration->getFeePlans());
        self::assertSame(['fr_FR' => ['title' => ['text' => 'Alma']]], $configuration->getDisplayTexts());
        self::assertTrue($configuration->isProductWidgetEnabled(), 'the widget is on unless the Merchant turns it off');
        self::assertSame('sk_live_a', $resolver->getApiKeyByMode(AlmaConfiguration::MODE_LIVE));
        self::assertSame('sk_test_a', $resolver->getApiKeyByMode(AlmaConfiguration::MODE_TEST));
        self::assertNull($resolver->getApiKeyByMode('sandbox'), 'an unknown mode has no key');
    }

    public function testAShopWithoutAlmaGetsTheDefaults(): void
    {
        $resolver = new AlmaPaymentMethodResolver($this->repositoryWith($this->method('offline', [])));

        $configuration = $resolver->getConfiguration();
        self::assertSame(AlmaConfiguration::MODE_TEST, $configuration->getApiMode());
        self::assertNull($configuration->getApiKeyTest());
        self::assertNull($configuration->getFeePlans());
        self::assertNull($configuration->getDisplayTexts());
        self::assertNull($resolver->getApiKeyByMode(AlmaConfiguration::MODE_TEST));
    }

    public function testEmptyAndMalformedValuesAreNormalised(): void
    {
        $resolver = new AlmaPaymentMethodResolver($this->repositoryWith(
            $this->method('alma', ['api_mode' => 'nonsense', 'api_key_test' => '', 'fee_plans' => [], 'display_texts' => 'not an array', 'product_widget_enabled' => false]),
        ));

        $configuration = $resolver->getConfiguration();
        self::assertSame(AlmaConfiguration::MODE_TEST, $configuration->getApiMode(), 'an unknown mode falls back to test');
        self::assertNull($configuration->getApiKeyTest(), 'an empty key is no key');
        self::assertNull($configuration->getFeePlans(), 'an empty list is no list');
        self::assertNull($configuration->getDisplayTexts(), 'a non-array is no texts');
        self::assertFalse($configuration->isProductWidgetEnabled());
        self::assertNull($resolver->getApiKeyByMode(AlmaConfiguration::MODE_TEST));
    }

    public function testIsAlmaLooksAtTheGatewayFactory(): void
    {
        $resolver = new AlmaPaymentMethodResolver($this->repositoryWith());

        self::assertTrue($resolver->isAlma($this->method('alma', [])));
        self::assertFalse($resolver->isAlma($this->method('offline', [])));
    }

    /** @return PaymentMethodRepositoryInterface<PaymentMethodInterface> */
    private function repositoryWith(PaymentMethodInterface ...$methods): PaymentMethodRepositoryInterface
    {
        $repository = $this->createMock(PaymentMethodRepositoryInterface::class);
        $repository->method('findAll')->willReturn($methods);

        return $repository;
    }

    /** @param array<string, mixed> $config */
    private function method(string $factory, array $config): PaymentMethodInterface
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getFactoryName')->willReturn($factory);
        $gatewayConfig->method('getConfig')->willReturn($config);
        $method = $this->createMock(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);

        return $method;
    }
}
