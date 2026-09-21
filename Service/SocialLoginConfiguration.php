<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SocialLogin\Service;

use SocialLogin\SocialLogin;
use Twig\Markup;

/**
 * The module's own settings, read the one way that answers them correctly.
 *
 * ModuleConfig stores everything as a string and hands back null for a row that is not
 * there, so a toggle stored as '0' is indistinguishable from a missing one for anything
 * that leans on PHP truthiness. Every boolean here is therefore a strict comparison
 * against '1', and every string is trimmed and emptied to null, so that a setting saved
 * blank by the back office screen counts as unset rather than as configured.
 */
final readonly class SocialLoginConfiguration
{
    public function __construct(
        private SvgLogoSanitizer $svgLogoSanitizer,
    ) {
    }

    /**
     * Whether the switch named by $enabledFieldName is on. The name itself is each
     * provider's own to give — {@see \SocialLogin\Provider\SocialLoginProviderInterface::getEnabledFieldName()}
     * — never rebuilt here from a provider code.
     */
    public function isEnabled(string $enabledFieldName): bool
    {
        return '1' === (string) SocialLogin::getConfigValue($enabledFieldName);
    }

    public function get(string $key): ?string
    {
        $value = SocialLogin::getConfigValue($key);

        if (null === $value) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }

    /**
     * A whole number setting, such as a provider's display order. Anything that is not a
     * plain non-negative integer (absent, blank, `abc`, `1.5`) reads as unset rather than
     * being coerced: `(int) 'abc'` would quietly move a provider to the front.
     */
    public function getPosition(string $positionFieldName): ?int
    {
        $value = $this->get($positionFieldName);

        if (null === $value || !ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param list<string> $keys
     */
    public function hasAll(array $keys): bool
    {
        foreach ($keys as $key) {
            if (null === $this->get($key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The merchant's own copy of a provider's official logo, read from the key named by
     * {@see \SocialLogin\Provider\SocialLoginProviderInterface::getLogoFieldName()} and
     * handed to {@see SvgLogoSanitizer}, which owns everything that makes it safe to print.
     * This method only composes the two: read, refuse an empty setting, sanitize. An empty
     * setting means "no custom logo", and the caller falls back to the theme's built-in
     * brand mark.
     */
    public function getCustomLogo(string $logoFieldName): ?Markup
    {
        $rawSvg = $this->get($logoFieldName);

        if (null === $rawSvg) {
            return null;
        }

        return $this->svgLogoSanitizer->sanitize($rawSvg);
    }
}
