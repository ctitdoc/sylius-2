<?php

declare(strict_types=1);

namespace Alma\Sylius\Validator\Constraint;

use Alma\Sylius\Resolver\AlmaPaymentMethodResolver;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Repository\PaymentMethodRepositoryInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * @implementation [[one-alma-configuration-per-shop]] — refuses a second Alma payment method
 */
final class UniqueAlmaPaymentMethodValidator extends ConstraintValidator
{
    /** @param PaymentMethodRepositoryInterface<PaymentMethodInterface> $paymentMethodRepository */
    public function __construct(
        private readonly PaymentMethodRepositoryInterface $paymentMethodRepository,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UniqueAlmaPaymentMethod) {
            throw new UnexpectedTypeException($constraint, UniqueAlmaPaymentMethod::class);
        }

        if ($value === null) {
            return;
        }

        if (!$value instanceof PaymentMethodInterface) {
            throw new UnexpectedValueException($value, PaymentMethodInterface::class);
        }

        $gatewayConfig = $value->getGatewayConfig();
        if (!$gatewayConfig instanceof GatewayConfigInterface) {
            return;
        }

        if ($gatewayConfig->getFactoryName() !== AlmaPaymentMethodResolver::GATEWAY_FACTORY_NAME) {
            return;
        }

        $currentId = $value->getId();

        foreach ($this->paymentMethodRepository->findAll() as $existing) {
            if (!$existing instanceof PaymentMethodInterface) {
                continue;
            }

            if ($existing->getId() !== null && $existing->getId() === $currentId) {
                continue;
            }

            $existingGatewayConfig = $existing->getGatewayConfig();
            if (!$existingGatewayConfig instanceof GatewayConfigInterface) {
                continue;
            }

            if ($existingGatewayConfig->getFactoryName() === AlmaPaymentMethodResolver::GATEWAY_FACTORY_NAME) {
                $this->context->buildViolation($constraint->message)->addViolation();

                return;
            }
        }
    }
}
