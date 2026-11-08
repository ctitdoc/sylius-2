<?php

declare(strict_types=1);

namespace Tests\Alma\Sylius\Behat\Context\Hook;

use Behat\Behat\Context\Context;
use Behat\Hook\BeforeScenario;

/** The Alma mock (tests/MockAlma) starts every scenario clean: no payment, no fraud flag. */
final class AlmaMockContext implements Context
{
    #[BeforeScenario]
    public function resetTheAlmaMock(): void
    {
        AlmaMock::control('POST', '/_test/reset');
    }
}
