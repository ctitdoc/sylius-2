<?php

declare(strict_types=1);

namespace Tests\Alma\Sylius\Behat\Context\Ui\Admin;

use Alma\Sylius\Resolver\AlmaPaymentMethodResolver;
use Behat\MinkExtension\Context\RawMinkContext;
use Behat\Step\Then;
use Behat\Step\When;
use Sylius\Behat\Page\Admin\PaymentMethod\CreatePageInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Webmozart\Assert\Assert;

/**
 * The Merchant configures Alma in the Back-Office ([[merchant-configuration-form]]): the Alma gateway's
 * fields of Sylius's payment method form, what the save verifies against Alma, what is stored.
 */
final class ConfiguringAlmaContext extends RawMinkContext
{
    public function __construct(
        private readonly CreatePageInterface $createPage,
        private readonly RepositoryInterface $paymentMethodRepository,
    ) {
    }

    #[When('I want to create a new Alma payment method')]
    public function iWantToCreateANewAlmaPaymentMethod(): void
    {
        $this->createPage->open(['factory' => AlmaPaymentMethodResolver::GATEWAY_FACTORY_NAME]);
    }

    #[When('I enter the test API key :apiKey')]
    public function iEnterTheTestApiKey(string $apiKey): void
    {
        $this->getSession()->getPage()->fillField('sylius_admin_payment_method_gatewayConfig_config_api_key_test', $apiKey);
        $this->getSession()->getPage()->selectFieldOption('sylius_admin_payment_method_gatewayConfig_config_api_mode', 'test');
    }

    #[Then('the Alma configuration should carry the merchant identifier :merchantId')]
    public function theAlmaConfigurationShouldCarryTheMerchantIdentifier(string $merchantId): void
    {
        Assert::same($this->almaConfig()['merchant_id'] ?? null, $merchantId);
    }

    #[Then('the plans Alma allows should be stored, with :planKey active by default')]
    public function thePlansAlmaAllowsShouldBeStoredWithActiveByDefault(string $planKey): void
    {
        $config = $this->almaConfig();
        Assert::isArray($config['fee_plans'] ?? null, 'the plans Alma allows are stored with the configuration');
        Assert::keyExists($config['fee_plans'], $planKey);
        Assert::keyExists($config['fee_plans'], 'general_10_0_0', 'every plan Alma allows is stored, active or not');
        $overrides = $config['fee_plan_overrides'] ?? null;
        Assert::isArray($overrides, 'the Merchant\'s overrides are stored with the configuration');
        Assert::isArray($overrides[$planKey] ?? null);
        Assert::true($overrides[$planKey]['enabled'] ?? false, sprintf('%s is active by default', $planKey));
        Assert::false(\is_array($overrides['general_2_0_0'] ?? null) && ($overrides['general_2_0_0']['enabled'] ?? false), 'the other plans wait for the Merchant');
    }

    #[Then('I should be notified that the API key is invalid or unreachable')]
    public function iShouldBeNotifiedThatTheApiKeyIsInvalid(): void
    {
        Assert::contains($this->getSession()->getPage()->getText(), 'This API key is invalid or unreachable.');
    }

    #[Then('I should be notified that an Alma payment method already exists')]
    public function iShouldBeNotifiedThatAnAlmaPaymentMethodAlreadyExists(): void
    {
        Assert::contains($this->getSession()->getPage()->getText(), 'An Alma payment method already exists.');
    }

    #[Then('no Alma payment method should exist')]
    public function noAlmaPaymentMethodShouldExist(): void
    {
        Assert::null($this->almaMethod());
    }

    private function almaMethod(): ?PaymentMethodInterface
    {
        foreach ($this->paymentMethodRepository->findAll() as $method) {
            if (!$method instanceof PaymentMethodInterface) {
                continue;
            }
            $config = $method->getGatewayConfig();
            if (null !== $config && AlmaPaymentMethodResolver::GATEWAY_FACTORY_NAME === $config->getFactoryName()) {
                return $method;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function almaConfig(): array
    {
        $method = $this->almaMethod();
        Assert::notNull($method, 'an Alma payment method exists');
        $config = $method->getGatewayConfig();
        Assert::notNull($config);

        return $config->getConfig();
    }
}
