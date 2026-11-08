<?php

declare(strict_types=1);

namespace Tests\Alma\Sylius\Behat\Context\Setup;

use Alma\Sylius\Api\ApiKeyValidator;
use Alma\Sylius\Api\FeePlansFetcher;
use Alma\Sylius\Resolver\AlmaPaymentMethodResolver;
use Behat\Behat\Context\Context;
use Behat\Step\Given;
use Sylius\Behat\Service\SharedStorageInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Webmozart\Assert\Assert;

/**
 * The Merchant configuration as a precondition ([[merchant-configuration]]): the Alma payment method
 * created directly, its key verified against the mock, the plans read from it, 2x, 3x and 4x active.
 */
final class AlmaConfigurationContext implements Context
{
    public function __construct(
        private readonly SharedStorageInterface $sharedStorage,
        private readonly FactoryInterface $paymentMethodFactory,
        private readonly FactoryInterface $gatewayConfigFactory,
        private readonly RepositoryInterface $paymentMethodRepository,
        private readonly ApiKeyValidator $apiKeyValidator,
        private readonly FeePlansFetcher $feePlansFetcher,
    ) {
    }

    #[Given('the store has the Alma payment method configured with the test key :apiKey')]
    public function theStoreHasTheAlmaPaymentMethodConfiguredWithTheTestKey(string $apiKey): void
    {
        $validation = $this->apiKeyValidator->validate($apiKey, 'test');
        if (!$validation->isSuccess()) {
            throw new \RuntimeException('The Alma mock refuses the key: is it running at ALMA_API_URL?');
        }
        $plans = $this->feePlansFetcher->fetch($apiKey, 'test');
        $overrides = [];
        foreach (['general_2_0_0', 'general_3_0_0', 'general_4_0_0'] as $key) {
            $overrides[$key] = ['enabled' => true, 'override_min_purchase_amount' => null, 'override_max_purchase_amount' => null];
        }

        $gatewayConfig = $this->gatewayConfigFactory->createNew();
        Assert::isInstanceOf($gatewayConfig, GatewayConfigInterface::class);
        $gatewayConfig->setGatewayName('alma');
        $gatewayConfig->setFactoryName(AlmaPaymentMethodResolver::GATEWAY_FACTORY_NAME);
        $gatewayConfig->setConfig([
            'api_mode' => 'test',
            'api_key_test' => $apiKey,
            'api_key_live' => '',
            'merchant_id' => $validation->getMerchantId(),
            'fee_plans' => $plans,
            'fee_plan_overrides' => $overrides,
            'product_widget_enabled' => false,
            'display_texts' => [],
        ]);

        $method = $this->paymentMethodFactory->createNew();
        Assert::isInstanceOf($method, PaymentMethodInterface::class);
        $method->setCode('alma');
        $method->setGatewayConfig($gatewayConfig);
        $method->setEnabled(true);
        $method->setCurrentLocale('en_US');
        $method->setFallbackLocale('en_US');
        $method->setName('Alma');
        if ($this->sharedStorage->has('channel')) {
            $channel = $this->sharedStorage->get('channel');
            if ($channel instanceof ChannelInterface) {
                $method->addChannel($channel);
            }
        }
        $this->paymentMethodRepository->add($method);
        $this->sharedStorage->set('payment_method', $method);
    }
}
