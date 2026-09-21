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

use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use League\OAuth2\Client\Token\AccessToken;
use SocialLogin\DTO\VerifiedIdentity;
use SocialLogin\Exception\ProviderCommunicationException;
use SocialLogin\Service\SocialLoginConfiguration;

/**
 * The plain authorization-code exchange, shared by every provider that does nothing more
 * than it: send the visitor to the provider with a `state`, take the code back, swap it
 * for a token at the provider's token endpoint, and read the profile with that token.
 *
 * What is common is also what carries the guarantees, so it lives in one place: nothing
 * from the callback other than the code is ever used, a response that is not the expected
 * shape is a refusal rather than a half-built identity, and a library error never escapes
 * as itself — it becomes {@see ProviderCommunicationException}, whose message is a
 * translation key and whose cause stays in `previous` for the log.
 *
 * What is left to each provider is the part that actually differs: the client it builds,
 * the scopes it asks for, and what its profile object means — in particular on what basis
 * the address may be called verified, which no two of them answer the same way.
 *
 * Apple is deliberately not here: its client secret is a JWT minted per exchange, its
 * callback is a cross-site POST, and its identity comes from a signed token it verifies
 * itself rather than from a profile endpoint.
 */
abstract readonly class AbstractLeagueOAuth2Provider implements SocialLoginProviderInterface
{
    use ProviderConfigurationKeys;

    public function __construct(
        protected SocialLoginConfiguration $configuration,
    ) {
    }

    public function getAuthorizationUrl(string $state, string $callbackUrl): string
    {
        return $this->createClient($callbackUrl)->getAuthorizationUrl([
            'state' => $state,
            'scope' => $this->getScopes(),
        ]);
    }

    public function fetchVerifiedIdentity(array $callbackParameters, string $callbackUrl): VerifiedIdentity
    {
        $code = $callbackParameters['code'] ?? null;

        if (!\is_string($code) || '' === $code) {
            throw new ProviderCommunicationException($this->getCode());
        }

        $client = $this->createClient($callbackUrl);

        try {
            $token = $client->getAccessToken('authorization_code', ['code' => $code]);

            if (!$token instanceof AccessToken) {
                throw new ProviderCommunicationException($this->getCode());
            }

            $user = $client->getResourceOwner($token);
        } catch (IdentityProviderException|\UnexpectedValueException $exception) {
            throw new ProviderCommunicationException($this->getCode(), $exception);
        }

        return $this->toVerifiedIdentity($user);
    }

    /**
     * Built per call, never held: listing the providers on a login page must not cost a
     * key parse, and a client carries the callback URL of one exchange.
     *
     * @throws \SocialLogin\Exception\ProviderNotConfiguredException
     */
    abstract protected function createClient(string $callbackUrl): AbstractProvider;

    /**
     * @return list<string>
     */
    abstract protected function getScopes(): array;

    /**
     * @throws ProviderCommunicationException when the profile is not the shape this
     *                                        provider's own contract promises
     */
    abstract protected function toVerifiedIdentity(ResourceOwnerInterface $user): VerifiedIdentity;

    protected function getConfiguration(): SocialLoginConfiguration
    {
        return $this->configuration;
    }
}
