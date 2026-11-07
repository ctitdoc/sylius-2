<?php

declare(strict_types=1);

namespace Tests\Alma\Sylius\Unit\Eligibility;

use Alma\Client\Application\DTO\EligibilityDto;
use Alma\Client\Application\Endpoint\EligibilityEndpoint;
use Alma\Client\Domain\Entity\Eligibility;
use Alma\Client\Domain\Entity\EligibilityList;
use Alma\Sylius\Api\EligibilityEndpointFactoryInterface;
use Alma\Sylius\Api\FeePlanAdapterCollectionBuilder;
use Alma\Sylius\Eligibility\EligibilityFetcher;
use Alma\Sylius\Eligibility\FeePlanCandidateProvider;
use Alma\Sylius\Entity\AlmaConfiguration;
use PHPUnit\Framework\TestCase;

/**
 * Characterisation of the eligibility check ([[eligibility-check]], [[eligibility]]): the Shop asks
 * Alma for the eligibility of an amount to the active plans only, with the key of the active mode,
 * and keeps Alma's answer for the plans it asked about. Pruned on 7 November 2026 (practices, "what a
 * unit test keeps"): the translation of an Alma failure into an exception — the Term's sentence, "if
 * Alma cannot be reached, no plan is eligible", belongs to the eligibility provider, not to this brick.
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

    private function fetcher(EligibilityEndpointFactoryInterface $factory): EligibilityFetcher
    {
        return new EligibilityFetcher(new FeePlanCandidateProvider(new FeePlanAdapterCollectionBuilder()), $factory);
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

    private function remote(int $installments, int $days, int $months, bool $eligible): Eligibility
    {
        $data = ['eligible' => $eligible, 'installments_count' => $installments, 'deferred_days' => $days, 'deferred_months' => $months];
        $data += $eligible
            ? ['customer_fee' => 0, 'customer_total_cost_amount' => 0, 'customer_total_cost_bps' => 0, 'payment_plan' => [], 'annual_interest_rate' => 0]
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
