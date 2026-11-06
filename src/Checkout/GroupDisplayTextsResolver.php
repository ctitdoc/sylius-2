<?php

declare(strict_types=1);

namespace Alma\Sylius\Checkout;

use Alma\Sylius\Checkout\View\GroupDisplayText;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Resolves a payment group's displayed title and description from the
 * merchant-edited `display_texts` config (cf. specs-sylius/module-configuration),
 * for both the checkout rendering and the Display section pre-fill.
 *
 * Fallback chain (canonical checkout Requirement « Group titles and descriptions
 * come from the merchant configuration »): exact locale → persisted values of
 * the store's English locale → the plugin's default wording (translation
 * catalog, English when the locale is not covered by the catalog).
 */
final class GroupDisplayTextsResolver
{
    private const FALLBACK_LOCALE = 'en';

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, array<string, array<string, mixed>>> $displayTexts
     */
    public function resolve(array $displayTexts, PlanGroup $group, string $locale): GroupDisplayText
    {
        $entry = $displayTexts[$locale][$group->value] ?? null;
        if (!\is_array($entry)) {
            $entry = $this->englishEntry($displayTexts, $group);
        }

        if ($entry !== null) {
            $title = $this->nonEmptyString($entry['title'] ?? null);
            if ($title !== null) {
                return new GroupDisplayText($title, $this->nonEmptyString($entry['description'] ?? null));
            }
        }

        return new GroupDisplayText(
            $this->trans($group->titleKey(), $locale),
            $this->defaultDescription($group, $locale),
        );
    }

    /**
     * Which groups carry a default description is a catalog fact, not a code
     * fact (canonical checkout spec: default wording changes go through the
     * translation catalog): no subtitle entry in any catalog means no default.
     */
    private function defaultDescription(PlanGroup $group, string $locale): ?string
    {
        $key = $group->subtitleKey();
        if (!$this->isCovered($key, $locale) && !$this->isCovered($key, self::FALLBACK_LOCALE)) {
            return null;
        }

        return $this->trans($key, $locale);
    }

    /**
     * @param array<string, array<string, array<string, mixed>>> $displayTexts
     *
     * @return array<string, mixed>|null
     */
    private function englishEntry(array $displayTexts, PlanGroup $group): ?array
    {
        foreach ($displayTexts as $locale => $groups) {
            if (!str_starts_with(strtolower((string) $locale), self::FALLBACK_LOCALE)) {
                continue;
            }
            if (\is_array($groups[$group->value] ?? null)) {
                return $groups[$group->value];
            }
        }

        return null;
    }

    private function trans(string $key, string $locale): string
    {
        return $this->translator->trans($key, [], null, $this->isCovered($key, $locale) ? $locale : self::FALLBACK_LOCALE);
    }

    private function isCovered(string $key, string $locale): bool
    {
        return $this->translator instanceof TranslatorBagInterface &&
            $this->translator->getCatalogue($locale)->has($key);
    }

    private function nonEmptyString(mixed $value): ?string
    {
        return \is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
