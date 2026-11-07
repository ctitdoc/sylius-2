<?php

declare(strict_types=1);

namespace Tests\Alma\Sylius\Unit\Validator;

use Alma\Sylius\Validator\Constraint\UniqueAlmaPaymentMethod;
use Alma\Sylius\Validator\Constraint\UniqueAlmaPaymentMethodValidator;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Repository\PaymentMethodRepositoryInterface;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

/**
 * Characterisation of the Rule [[one-alma-configuration-per-shop]]: a second Alma payment method is
 * refused; editing the existing one, or another gateway, is not.
 *
 * @implementation [[one-alma-configuration-per-shop]]
 */
final class UniqueAlmaPaymentMethodValidatorTest extends TestCase
{
    public function testASecondAlmaMethodIsRefused(): void
    {
        $existing = $this->method('alma', 1);
        $candidate = $this->method('alma', null);

        $this->validate($candidate, [$existing], expectViolation: true);
    }

    public function testEditingTheExistingAlmaMethodIsAllowed(): void
    {
        $existing = $this->method('alma', 1);

        $this->validate($existing, [$existing, $this->method('offline', 2)], expectViolation: false);
    }

    public function testAnotherGatewayIsNotConcerned(): void
    {
        $this->validate($this->method('offline', null), [$this->method('alma', 1)], expectViolation: false);
    }

    /** @param list<PaymentMethodInterface> $all */
    private function validate(PaymentMethodInterface $value, array $all, bool $expectViolation): void
    {
        $repository = $this->createMock(PaymentMethodRepositoryInterface::class);
        $repository->method('findAll')->willReturn($all);
        $context = $this->createMock(ExecutionContextInterface::class);
        if ($expectViolation) {
            $builder = $this->createMock(ConstraintViolationBuilderInterface::class);
            $builder->expects(self::once())->method('addViolation');
            $context->expects(self::once())->method('buildViolation')->with('alma_sylius.form.error.payment_method_already_exists')->willReturn($builder);
        } else {
            $context->expects(self::never())->method('buildViolation');
        }

        $validator = new UniqueAlmaPaymentMethodValidator($repository);
        $validator->initialize($context);
        $validator->validate($value, new UniqueAlmaPaymentMethod());
    }

    private function method(string $factory, ?int $id): PaymentMethodInterface
    {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getFactoryName')->willReturn($factory);
        $method = $this->createMock(PaymentMethodInterface::class);
        $method->method('getGatewayConfig')->willReturn($gatewayConfig);
        $method->method('getId')->willReturn($id);

        return $method;
    }
}
