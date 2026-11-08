<?php

declare(strict_types=1);

namespace Tests\Alma\Sylius\Behat\Context\Ui\Shop;

use Alma\Sylius\Repository\AlmaPaymentReferenceRepository;
use Behat\Mink\Driver\BrowserKitDriver;
use Behat\Mink\Element\NodeElement;
use Behat\MinkExtension\Context\RawMinkContext;
use Behat\Step\Then;
use Behat\Step\When;
use Sylius\Behat\Page\Shop\Checkout\CompletePageInterface;
use Sylius\Behat\Service\SharedStorageInterface;
use Symfony\Component\HttpKernel\HttpKernelBrowser;
use Tests\Alma\Sylius\Behat\Context\Hook\AlmaMock;
use Webmozart\Assert\Assert;

/**
 * The Buyer at checkout ([[payment-plan-choice]]) and the hand-over to Alma ([[payment-creation]],
 * [[payment-reference]]): what the payment step offers, the plan chosen, the redirect the order
 * confirmation answers with, and what Alma then holds.
 */
final class PayingWithAlmaContext extends RawMinkContext
{
    public function __construct(
        private readonly CompletePageInterface $completePage,
        private readonly SharedStorageInterface $sharedStorage,
        private readonly AlmaPaymentReferenceRepository $paymentReferences,
    ) {
    }

    #[Then('Alma should be offered with the plans :first, :second and :third')]
    public function almaShouldBeOfferedWithThePlans(string $first, string $second, string $third): void
    {
        $page = $this->getSession()->getPage();
        Assert::notNull($page->find('css', '[data-test-payment-item]:contains("Alma")'), 'Alma is among the payment methods');
        foreach ([$first, $second, $third] as $planKey) {
            Assert::notNull($this->planRadio($planKey), sprintf('the plan %s is offered', $planKey));
        }
    }

    #[Then('Alma should not be offered')]
    public function almaShouldNotBeOffered(): void
    {
        Assert::null($this->getSession()->getPage()->find('css', '[data-test-payment-item]:contains("Alma")'), 'Alma is not among the payment methods');
    }

    #[When('I choose the Alma plan :planKey')]
    public function iChooseTheAlmaPlan(string $planKey): void
    {
        $radio = $this->planRadio($planKey);
        Assert::notNull($radio, sprintf('the plan %s is offered', $planKey));
        $radio->selectOption($planKey);
    }

    #[When('I confirm my order with Alma')]
    public function iConfirmMyOrderWithAlma(): void
    {
        $client = $this->browser();
        $client->followRedirects(false);

        try {
            $this->completePage->confirmOrder();
            $location = $this->followInternalRedirects($client);
        } finally {
            $client->followRedirects(true);
        }
        $this->sharedStorage->set('alma_hand_over_url', $location);
    }

    #[Then("I should be handed over to Alma's hosted page")]
    public function iShouldBeHandedOverToAlmasHostedPage(): void
    {
        $location = $this->sharedStorage->get('alma_hand_over_url');
        Assert::string($location);
        Assert::startsWith($location, AlmaMock::url() . '/pay/', 'the Buyer is redirected to the hosted page of the payment');
    }

    #[Then('Alma should hold a payment of :cents cents in :count instalments for my order')]
    public function almaShouldHoldAPaymentForMyOrder(int $cents, int $count): void
    {
        $payments = AlmaMock::payments();
        Assert::count($payments, 1, 'one payment created at Alma');
        $payment = $payments[0];
        Assert::same($payment['purchase_amount'] ?? null, $cents);
        Assert::same($payment['installments_count'] ?? null, $count);
        Assert::same($payment['processing_status'] ?? null, 'awaiting_authorization', 'nothing is paid before the Buyer acts on the hosted page');
        $this->sharedStorage->set('alma_payment_id', $payment['id'] ?? null);
    }

    #[Then('the Shop should keep the reference of that payment')]
    public function theShopShouldKeepTheReferenceOfThatPayment(): void
    {
        $id = $this->sharedStorage->get('alma_payment_id');
        Assert::string($id);
        Assert::notNull($this->paymentReferences->findOneByAlmaPaymentId($id), 'the payment reference ties the Alma payment to the Order');
    }

    private function planRadio(string $planKey): ?NodeElement
    {
        return $this->getSession()->getPage()->find('css', sprintf('input[type="radio"][name$="[alma_fee_plan_key]"][value="%s"]', $planKey));
    }

    private function browser(): HttpKernelBrowser
    {
        $driver = $this->getSession()->getDriver();
        Assert::isInstanceOf($driver, BrowserKitDriver::class, 'the symfony session runs on the BrowserKit driver');
        $client = $driver->getClient();
        Assert::isInstanceOf($client, HttpKernelBrowser::class, 'the symfony session drives the kernel directly');

        return $client;
    }

    /** Follows the redirects that stay in the Shop; returns the first one that leaves it. */
    private function followInternalRedirects(HttpKernelBrowser $client): string
    {
        for ($i = 0; $i < 10; ++$i) {
            $response = $client->getInternalResponse();
            $location = $response->getHeader('Location');
            if (!\is_string($location) || '' === $location || $response->getStatusCode() < 300 || $response->getStatusCode() >= 400) {
                throw new \RuntimeException(sprintf('No redirect to Alma: status %d, %s', $response->getStatusCode(), mb_substr(strip_tags($response->getContent()), 0, 300)));
            }
            $host = parse_url($location, \PHP_URL_HOST);
            $shopHost = parse_url($client->getInternalRequest()->getUri(), \PHP_URL_HOST);
            if (\is_string($host) && $host !== $shopHost) {
                return $location;
            }
            $client->followRedirect();
        }

        throw new \RuntimeException('Too many redirects inside the Shop');
    }
}
