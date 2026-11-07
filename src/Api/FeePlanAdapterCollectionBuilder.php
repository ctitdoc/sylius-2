<?php

declare(strict_types=1);

namespace Alma\Sylius\Api;

use Alma\Sylius\Entity\AlmaConfiguration;

/**
 * @phpstan-import-type StoredPlan from FeePlanAdapter
 * @phpstan-import-type PlanOverride from FeePlanAdapter
 */
final class FeePlanAdapterCollectionBuilder
{
    /**
     * Builds a map of plan_key → FeePlanAdapter from the persisted singleton
     * configuration. Iteration order follows the persisted fee_plans JSON.
     *
     * @return array<string, FeePlanAdapter>
     */
    public function build(AlmaConfiguration $configuration): array
    {
        // The one point where the JSON column is trusted: the plans were written by FeePlansFetcher from the
        // client's typed getters, the overrides by the configuration form — see FeePlanAdapter.
        /** @var array<string, StoredPlan> $apiPlans */
        $apiPlans = $configuration->getFeePlans() ?? [];
        /** @var array<string, PlanOverride> $overrides */
        $overrides = $configuration->getFeePlanOverrides() ?? [];

        $result = [];
        foreach ($apiPlans as $planKey => $apiData) {
            $result[$planKey] = new FeePlanAdapter($apiData, $overrides[$planKey] ?? []);
        }

        return $result;
    }
}
