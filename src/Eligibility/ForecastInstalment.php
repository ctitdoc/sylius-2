<?php

declare(strict_types=1);

namespace Alma\Sylius\Eligibility;

use Alma\Client\Domain\Entity\Installment;

/**
 * One instalment of the forecast schedule of an eligibility answer ([[instalment-schedule]]): the
 * client's typed Installment, and the amount Alma announces for it (`total_amount`), which the
 * Buyer is shown; EligibilityFetcher checks it against share plus fees plus interest and reports a
 * difference. Amounts are integers in cents, as everywhere between Sylius, the plugin and Alma.
 *
 * @implementation [[payment-plan-choice]]
 */
final class ForecastInstalment
{
    public function __construct(
        public readonly Installment $installment,
        public readonly int $amount,
    ) {
    }

    public function getDueDate(): int
    {
        return $this->installment->getDueDate();
    }
}
