<?php

declare(strict_types=1);

namespace App\Infrastructure\Localisation;

use Symfony\Component\Intl\Countries as IntlCountries;
use Symfony\Component\Translation\LocaleSwitcher;

final readonly class CountryNames
{
    public function __construct(
        private LocaleSwitcher $localeSwitcher,
    ) {
    }

    /**
     * @param string[] $countryCodes the alpha-2 country codes
     *
     * @return string[] the localized country names
     */
    public function names(array $countryCodes): array
    {
        $names = [];
        foreach ($countryCodes as $countryCode) {
            $names[] = $this->name($countryCode);
        }

        return $names;
    }

    public function name(string $countryCode): string
    {
        $countryCode = strtolower($countryCode);
        if ('xk' === $countryCode) {
            // Symfony does not support Kosovo as a country.
            return 'Kosovo';
        }

        $name = IntlCountries::getName(
            country: strtoupper($countryCode),
            displayLocale: $this->localeSwitcher->getLocale(),
        );

        return '' !== $name ? $name : strtoupper($countryCode);
    }
}
