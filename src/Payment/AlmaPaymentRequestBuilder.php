<?php

declare(strict_types=1);

namespace Alma\Sylius\Payment;

use Alma\Client\Application\DTO\CartDto;
use Alma\Client\Application\DTO\CartItemDto;
use Alma\Client\Application\DTO\CustomerDto;
use Alma\Client\Application\DTO\OrderDto;
use Alma\Client\Application\DTO\PaymentDto;
use Alma\Sylius\Eligibility\SyliusAddressMapper;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds the three DTOs sent to PaymentEndpoint::create() from the Sylius
 * payment + order + the customer-selected fee plan key. The mapping decisions
 * (locale fallback, customData keys, MVP B2C defaults, etc.) follow the
 * Requirement "Payment is created with cart, customer, and order data" in
 * docs/specs/payment-creation/spec.md.
 *
 * Field names on the DTOs are sourced from the Alma PHP client v3
 * (Alma\Client\Application\DTO\*) — the spec describes intent, the client
 * describes the technical contract.
 *
 * @implements [[payment-creation]] — the request sent to Alma: amount, plan, addresses, cart, customer, URLs
 */
class AlmaPaymentRequestBuilder
{
    public function __construct(
        private readonly SyliusAddressMapper $addressMapper,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function build(PaymentInterface $payment, string $planKey): AlmaPaymentRequest
    {
        $order = $payment->getOrder();
        if ($order === null) {
            throw new \LogicException('Cannot build Alma payment request: payment has no order.');
        }

        [$installments, $deferredDays, $deferredMonths] = $this->parsePlanKey($planKey);

        $billingAddress = $order->getBillingAddress();
        $shippingAddress = $order->getShippingAddress();

        $email = $order->getCustomer()?->getEmail();

        $paymentDto = new PaymentDto($order->getTotal());
        $paymentDto
            ->setInstallmentsCount($installments)
            ->setDeferredDays($deferredDays)
            ->setDeferredMonths($deferredMonths)
            ->setOrigin(PaymentDto::ORIGIN_ONLINE)
            ->setReturnUrl($this->absoluteUrl('alma_sylius_payment_return'))
            ->setCustomerCancelUrl($this->absoluteUrl('alma_sylius_payment_cancel'))
            ->setIpnCallbackUrl($this->absoluteUrl('alma_sylius_payment_ipn'))
            ->setCustomData([
                'order_id' => $order->getNumber(),
                'order_internal_id' => $order->getId(),
            ])
        ;

        $locale = $this->resolveLocale($order);
        if ($locale !== null) {
            $paymentDto->setLocale($locale);
        }

        $billingDto = $this->addressMapper->toAlmaAddress($billingAddress, $email);
        if ($billingDto !== null) {
            $paymentDto->setBillingAddress($billingDto);
        }
        $shippingDto = $this->addressMapper->toAlmaAddress($shippingAddress, $email);
        if ($shippingDto !== null) {
            $paymentDto->setShippingAddress($shippingDto);
        }

        $cartDto = new CartDto();
        foreach ($order->getItems() as $item) {
            $cartItem = new CartItemDto(
                $item->getQuantity(),
                $item->getTotal(),
                (string) $item->getProductName(),
            );
            $cartItem->setUnitPrice($item->getUnitPrice());
            $variant = $item->getVariant();
            if ($variant !== null && $variant->getCode() !== null) {
                $cartItem->setSku($variant->getCode());
            }
            $cartDto->addItem($cartItem);
        }
        $paymentDto->setCart($cartDto);

        $orderDto = new OrderDto();
        if ($order->getNumber() !== null) {
            $orderDto->setMerchantReference($order->getNumber());
        }
        $merchantUrl = $this->maybeAbsoluteUrlForRoute('sylius_admin_order_show', ['id' => $order->getId()]);
        if ($merchantUrl !== null) {
            $orderDto->setMerchantUrl($merchantUrl);
        }
        $customerUrl = $this->maybeAbsoluteUrlForRoute('sylius_shop_order_show', ['tokenValue' => $order->getTokenValue()]);
        if ($customerUrl !== null) {
            $orderDto->setCustomerUrl($customerUrl);
        }

        $customerDto = $this->buildCustomerDto($order, $billingAddress);

        return new AlmaPaymentRequest($paymentDto, $orderDto, $customerDto);
    }

    private function buildCustomerDto(OrderInterface $order, ?AddressInterface $billing): CustomerDto
    {
        $customer = $order->getCustomer();

        $firstName = $customer?->getFirstName() ?? $billing?->getFirstName() ?? '';
        $lastName = $customer?->getLastName() ?? $billing?->getLastName() ?? '';
        $email = $customer?->getEmail() ?? '';

        $dto = new CustomerDto();
        $dto->setFirstName($firstName);
        $dto->setLastName($lastName);
        $dto->setEmail($email);
        $dto->setIsBusiness(false);

        $phone = $customer?->getPhoneNumber() ?? $billing?->getPhoneNumber();
        if ($phone !== null && $phone !== '') {
            $dto->setPhone($phone);
        }

        $billingDto = $this->addressMapper->toAlmaAddress($billing, $email !== '' ? $email : null);
        if ($billingDto !== null) {
            $dto->addAddress($billingDto);
        }

        return $dto;
    }

    /**
     * @return array{int, int, int} [installmentsCount, deferredDays, deferredMonths]
     */
    private function parsePlanKey(string $planKey): array
    {
        // <kind>_<installments>_<deferred_days>_<deferred_months>
        $parts = explode('_', $planKey);
        $count = count($parts);
        if ($count < 4) {
            throw new \InvalidArgumentException(sprintf('Invalid fee plan key "%s".', $planKey));
        }

        $installments = (int) $parts[$count - 3];
        $deferredDays = (int) $parts[$count - 2];
        $deferredMonths = (int) $parts[$count - 1];

        return [$installments, $deferredDays, $deferredMonths];
    }

    private function resolveLocale(OrderInterface $order): ?string
    {
        $orderLocale = $order->getLocaleCode();
        if ($this->isValidLocale($orderLocale)) {
            return $orderLocale;
        }

        $channel = $order->getChannel();
        $channelDefault = $channel?->getDefaultLocale()?->getCode();

        return $this->isValidLocale($channelDefault) ? $channelDefault : null;
    }

    private function isValidLocale(?string $locale): bool
    {
        return $locale !== null && preg_match('/^[a-z]{2}_[A-Z]{2}$/', $locale) === 1;
    }

    /**
     * Bare URL with no query parameter — Alma appends `?pid=<alma_payment_id>`
     * when it invokes the callback (cf. "Three callback URLs are registered").
     */
    private function absoluteUrl(string $route): string
    {
        return $this->urlGenerator->generate(
            $route,
            [],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function maybeAbsoluteUrlForRoute(string $route, array $parameters): ?string
    {
        try {
            return $this->urlGenerator->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (\Throwable) {
            return null;
        }
    }
}
