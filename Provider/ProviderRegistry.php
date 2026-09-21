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

namespace SocialLogin\Provider;

use SocialLogin\Exception\ProviderNotConfiguredException;
use SocialLogin\Exception\UnknownProviderException;
use SocialLogin\Service\SocialLoginConfiguration;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The providers the shop has, and the only way to reach one by the code a URL carries.
 *
 * A code taken from a request is never turned into a service id or a class name here:
 * it is matched against what the container tagged, and anything else is refused.
 *
 * Every list it hands out is in the order the merchant chose ({@see self::sortByPosition()}),
 * so the storefront buttons and the back office blocks can never disagree about it.
 */
final readonly class ProviderRegistry
{
    /**
     * @param iterable<SocialLoginProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator('sociallogin.provider')]
        private iterable $providers,
        private SocialLoginConfiguration $configuration,
    ) {
    }

    /**
     * Every provider the shop has code for, configured or not, keyed by code and in display
     * order — what a back office screen lists, since a provider is configured *there*.
     *
     * @return array<string, SocialLoginProviderInterface>
     */
    public function getAllProviders(): array
    {
        $all = [];

        foreach ($this->providers as $provider) {
            $all[$provider->getCode()] = $provider;
        }

        return $this->sortByPosition($all);
    }

    /**
     * Those a login page may offer, keyed by code and in display order.
     *
     * @return array<string, SocialLoginProviderInterface>
     */
    public function getConfiguredProviders(): array
    {
        return array_filter(
            $this->getAllProviders(),
            static fn (SocialLoginProviderInterface $provider): bool => $provider->isConfigured(),
        );
    }

    /**
     * @throws UnknownProviderException       when no provider answers to that code
     * @throws ProviderNotConfiguredException when the shop turned it off or left it incomplete
     */
    public function getProvider(string $code): SocialLoginProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->getCode() !== $code) {
                continue;
            }

            if (!$provider->isConfigured()) {
                throw new ProviderNotConfiguredException($code);
            }

            return $provider;
        }

        throw new UnknownProviderException($code);
    }

    /**
     * Lowest position first; a provider with no position comes after every provider that
     * has one. PHP sorts are stable since 8.0, so providers sharing a position, or having
     * none, keep the order the container registered them in.
     *
     * @param array<string, SocialLoginProviderInterface> $providers
     *
     * @return array<string, SocialLoginProviderInterface>
     */
    private function sortByPosition(array $providers): array
    {
        $positions = [];

        foreach ($providers as $code => $provider) {
            $positions[$code] = $this->configuration->getPosition($provider->getPositionFieldName()) ?? \PHP_INT_MAX;
        }

        uksort($providers, static fn (string $left, string $right): int => $positions[$left] <=> $positions[$right]);

        return $providers;
    }
}
