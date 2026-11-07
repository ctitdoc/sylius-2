<?php

declare(strict_types=1);

namespace Alma\Sylius\Twig;

use Alma\Sylius\Resolver\AlmaPaymentMethodResolver;
use Sylius\Bundle\AdminBundle\Twig\PaymentMethodExtension;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Repository\PaymentMethodRepositoryInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Decorates {@see PaymentMethodExtension::getPaymentGateways()} (the data feed
 * of the admin "choose a gateway factory" page) to hide the `alma` factory
 * once an Alma PaymentMethod already exists.
 *
 * Defense in depth complement to {@see \Alma\Sylius\Validator\Constraint\UniqueAlmaPaymentMethodValidator}:
 * the validator rejects a second submit, this decorator removes the UX path
 * that would let an admin even try. Both are required by
 * specs-sylius/module-configuration, Requirement "Only one Alma PaymentMethod
 * per shop".
 */
/**
 * @implementation [[one-alma-configuration-per-shop]] — removes Alma from the admin gateway choice once a method exists
 */
final class AlmaPaymentGatewaysDecorator extends AbstractExtension
{
    /** @param PaymentMethodRepositoryInterface<PaymentMethodInterface> $paymentMethodRepository */
    public function __construct(
        private readonly PaymentMethodExtension $inner,
        private readonly PaymentMethodRepositoryInterface $paymentMethodRepository,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('sylius_admin_get_payment_gateways', [$this, 'getPaymentGateways']),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getPaymentGateways(): array
    {
        $gateways = $this->inner->getPaymentGateways();

        if ($this->almaPaymentMethodExists()) {
            unset($gateways[AlmaPaymentMethodResolver::GATEWAY_FACTORY_NAME]);
        }

        return $gateways;
    }

    private function almaPaymentMethodExists(): bool
    {
        foreach ($this->paymentMethodRepository->findAll() as $paymentMethod) {
            if (!$paymentMethod instanceof PaymentMethodInterface) {
                continue;
            }

            $gatewayConfig = $paymentMethod->getGatewayConfig();
            if (!$gatewayConfig instanceof GatewayConfigInterface) {
                continue;
            }

            if ($gatewayConfig->getFactoryName() === AlmaPaymentMethodResolver::GATEWAY_FACTORY_NAME) {
                return true;
            }
        }

        return false;
    }
}
