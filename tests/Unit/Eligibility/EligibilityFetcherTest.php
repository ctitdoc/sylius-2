<?php

declare(strict_types=1);

namespace Tests\Alma\Sylius\Unit\Eligibility;

use Alma\Client\Application\DTO\EligibilityDto;
use Alma\Client\Application\Endpoint\EligibilityEndpoint;
use Alma\Client\Application\Exception\Endpoint\EligibilityEndpointException;
use Alma\Client\Domain\Entity\Eligibility;
use Alma\Client\Domain\Entity\EligibilityList;
use Alma\Sylius\Api\EligibilityEndpointFactoryInterface;
use Alma\Sylius\Api\FeePlanAdapterCollectionBuilder;
use Alma\Sylius\Eligibility\EligibilityFetcher;
use Alma\Sylius\Eligibility\EligibilityFetchFailedException;
use Alma\Sylius\Eligibility\FeePlanCandidateProvider;
use Alma\Sylius\Entity\AlmaConfiguration;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Characterisation of the eligibility check ([[eligibility-check]], [[eligibility]]): the Shop asks
 * Alma for the eligibility of an amount to the active plans only, with the key of the active mode,
 * and keeps Alma's answer for the plans it asked about.
 *
 * @implementation [[eligibility-check]]
 */
final class EligibilityFetcherTest extends TestCase
{
    /** @var array<string, array<string, mixed>> two plans allowed by Alma, 50 € to 2 000 € */
    private const PLANS = [
        'general_3_0_0' => ['kind' => 'general', 'installments_count' => 3, 'deferred_days' => 0, 'deferred_months' => 0, 'allowed' => true, 'min_purchase_amount' => 5000, 'max_purchase_amount' => 200000],
        'general_1_15_0' => ['kind' => 'general', 'installments_count' => 1, 'deferred_days' => 15, 'deferred_months' => 0, 'allowed' => true, 'min_purchase_amount' => 5000, 'max_purchase_amount' => 100000],
    ];

    public function testOnlyTheActivePlansEligibleForTheAmountAreAskedWithTheKeyOfTheActiveMode(): void
    {
        $endpoint = $this->createMock(EligibilityEndpoint::class);
        $endpoint->expects(self::once())->method('getEligibilityList')->with(self::callback(function (EligibilityDto $dto): bool {
            $sent = $dto->toArray();
            self::assertSame(6856, $sent['purchase_amount'] ?? null);
            self::assertCount(1, $sent['queries'] ?? [], 'the 3x plan only: the deferred one is not enabled');
            self::assertSame(3, $sent['queries'][0]['installments_count'] ?? null);

            return true;
        }))->willReturn($this->list([$this->remote(3, 0, 0, true)]));
        $factory = $this->createMock(EligibilityEndpointFactoryInterface::class);
        $factory->expects(self::once())->method('make')->with('sk_live_x', 'live')->willReturn($endpoint);

        $result = $this->fetcher($factory)->fetch($this->configuration('live', enabled: ['general_3_0_0']), 6856);

        self::assertSame(['general_3_0_0'], array_keys($result));
        self::assertTrue($result['general_3_0_0']->isEligible());
        self::assertSame('general_3_0_0', $result['general_3_0_0']->getPlanKey());
    }

    public function testNothingIsAskedWhenNoPlanIsACandidateOrTheModeHasNoKey(): void
    {
        $factory = $this->createMock(EligibilityEndpointFactoryInterface::class);
        $factory->expects(self::never())->method('make');
        $fetcher = $this->fetcher($factory);

        self::assertSame([], $fetcher->fetch($this->configuration('test', enabled: []), 6856), 'no plan enabled');
        self::assertSame([], $fetcher->fetch($this->configuration('test', enabled: ['general_3_0_0']), 4000), 'amount below every plan');
        $noKey = $this->configuration('test', enabled: ['general_3_0_0']);
        $noKey->setApiKeyTest(null);
        self::assertSame([], $fetcher->fetch($noKey, 6856), 'no key for the active mode');
    }

    public function testAlmasAnswerIsKeptForThePlansAskedAboutOnly(): void
    {
        $endpoint = $this->createMock(EligibilityEndpoint::class);
        $endpoint->method('getEligibilityList')->willReturn($this->list([
            $this->remote(3, 0, 0, true),
            $this->remote(1, 15, 0, false),
            $this->remote(4, 0, 0, true), // not a candidate: not configured
        ]));
        $factory = $this->createMock(EligibilityEndpointFactoryInterface::class);
        $factory->method('make')->willReturn($endpoint);

        $result = $this->fetcher($factory)->fetch($this->configuration('test', enabled: ['general_3_0_0', 'general_1_15_0']), 6856);

        self::assertSame(['general_3_0_0', 'general_1_15_0'], array_keys($result));
        self::assertTrue($result['general_3_0_0']->isEligible());
        self::assertFalse($result['general_1_15_0']->isEligible(), "Alma's refusal is kept as such");
    }

    public function testAnAlmaFailureBecomesAFetchFailure(): void
    {
        $endpoint = $this->createMock(EligibilityEndpoint::class);
        $endpoint->method('getEligibilityList')->willThrowException(new EligibilityEndpointException('Alma is down'));
        $factory = $this->createMock(EligibilityEndpointFactoryInterface::class);
        $factory->method('make')->willReturn($endpoint);

        $this->expectException(EligibilityFetchFailedException::class);
        $this->expectExceptionMessage('Alma is down');

        $this->fetcher($factory)->fetch($this->configuration('test', enabled: ['general_3_0_0']), 6856);
    }

    public function testTheScheduleRowsBecomePendingInstalmentsTypedByTheClient(): void
    {
        $endpoint = $this->createMock(EligibilityEndpoint::class);
        $endpoint->method('getEligibilityList')->willReturn($this->list([$this->remote(3, 0, 0, true, [
            ['due_date' => 1762473600, 'purchase_amount' => '2286', 'customer_fee' => 0, 'customer_interest' => 0, 'total_amount' => 2286, 'localized_due_date' => '07/11/2026', 'time_delta_from_start' => null],
            ['due_date' => 1765065600, 'purchase_amount' => 2285, 'customer_fee' => 0, 'customer_interest' => 0, 'total_amount' => 2285],
        ])]));
        $factory = $this->createMock(EligibilityEndpointFactoryInterface::class);
        $factory->method('make')->willReturn($endpoint);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $result = $this->fetcher($factory, $logger)->fetch($this->configuration('test', enabled: ['general_3_0_0']), 6856);
        $instalments = $result['general_3_0_0']->getInstallments();

        self::assertCount(2, $instalments);
        self::assertSame(2286, $instalments[0]->installment->getPurchaseAmount(), 'a numeric string, typed to int by the client');
        self::assertSame(1762473600, $instalments[0]->getDueDate());
        self::assertSame('pending', $instalments[0]->installment->getState(), 'a forecast instalment has no other state');
        self::assertSame(2286, $instalments[0]->amount, "the amount shown is Alma's total_amount");
    }

    public function testARowTheClientRejectsIsSkippedAndReportedAndAMismatchedTotalIsReported(): void
    {
        $endpoint = $this->createMock(EligibilityEndpoint::class);
        $endpoint->method('getEligibilityList')->willReturn($this->list([$this->remote(3, 0, 0, true, [
            ['due_date' => 1762473600, 'purchase_amount' => [2286], 'customer_fee' => 0, 'customer_interest' => 0],
            ['due_date' => 1765065600, 'customer_fee' => 0, 'customer_interest' => 0],
            ['due_date' => 1767657600, 'purchase_amount' => 2285, 'customer_fee' => 0, 'customer_interest' => 0, 'total_amount' => 2290],
        ])]));
        $factory = $this->createMock(EligibilityEndpointFactoryInterface::class);
        $factory->method('make')->willReturn($endpoint);
        $logger = $this->createMock(LoggerInterface::class);
        $warnings = [];
        $logger->method('warning')->willReturnCallback(static function (string $message, array $context) use (&$warnings): void {
            $warnings[] = [$message, $context['row'] ?? null];
        });

        $result = $this->fetcher($factory, $logger)->fetch($this->configuration('test', enabled: ['general_3_0_0']), 6856);

        $kept = $result['general_3_0_0']->getInstallments();
        self::assertCount(1, $kept, 'the two rejected rows are skipped, the third kept');
        self::assertSame(2290, $kept[0]->amount, "Alma's total_amount is shown as it always was, the difference reported");
        self::assertSame([
            ['alma.eligibility.row_rejected', 0],
            ['alma.eligibility.row_rejected', 1],
            ['alma.eligibility.total_amount_mismatch', 2],
        ], $warnings);
    }

    private function fetcher(EligibilityEndpointFactoryInterface $factory, ?LoggerInterface $logger = null): EligibilityFetcher
    {
        return new EligibilityFetcher(new FeePlanCandidateProvider(new FeePlanAdapterCollectionBuilder()), $factory, $logger ?? new NullLogger());
    }

    /** @param list<string> $enabled */
    private function configuration(string $mode, array $enabled): AlmaConfiguration
    {
        $configuration = new AlmaConfiguration();
        $configuration->setApiMode($mode);
        $configuration->setApiKeyLive('sk_live_x');
        $configuration->setApiKeyTest('sk_test_x');
        $configuration->setFeePlans(self::PLANS);
        $overrides = [];
        foreach ($enabled as $key) {
            $overrides[$key] = ['enabled' => true];
        }
        $configuration->setFeePlanOverrides($overrides);

        return $configuration;
    }

    /** @param list<array<string, mixed>> $plan */
    private function remote(int $installments, int $days, int $months, bool $eligible, array $plan = []): Eligibility
    {
        $data = ['eligible' => $eligible, 'installments_count' => $installments, 'deferred_days' => $days, 'deferred_months' => $months];
        $data += $eligible
            ? ['customer_fee' => 0, 'customer_total_cost_amount' => 0, 'customer_total_cost_bps' => 0, 'payment_plan' => $plan, 'annual_interest_rate' => 0]
            : ['constraints' => [], 'reasons' => ['purchase_amount' => 'too_low']];

        return new Eligibility($data);
    }

    /** @param list<Eligibility> $items */
    private function list(array $items): EligibilityList
    {
        $list = new EligibilityList();
        foreach ($items as $item) {
            $list->add($item);
        }

        return $list;
    }
}
