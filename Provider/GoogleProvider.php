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

use League\OAuth2\Client\Provider\Google;
use League\OAuth2\Client\Provider\GoogleUser;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use SocialLogin\DTO\VerifiedIdentity;
use SocialLogin\Exception\ProviderCommunicationException;
use SocialLogin\Exception\ProviderNotConfiguredException;
use SocialLogin\SocialLogin;

/**
 * Sign in with Google, over OpenID Connect.
 *
 * What the returned identity rests on: the authorization code is exchanged at
 * https://oauth2.googleapis.com/token over TLS, authenticated with the shop's own
 * client secret, and the profile is then read from Google's userinfo endpoint over TLS
 * with the resulting bearer token. Nothing in the callback query string other than the
 * code is trusted, and the code alone is worthless to anyone who does not also hold the
 * secret. The `email_verified` claim is Google's, read from that userinfo response.
 *
 * The exchange itself is {@see AbstractLeagueOAuth2Provider}'s; what is here is what is
 * Google's own.
 */
final readonly class GoogleProvider extends AbstractLeagueOAuth2Provider
{
    /** OpenID Connect wants `openid`; `email` and `profile` are what an account needs and no more. */
    private const array SCOPES = ['openid', 'email', 'profile'];

    public function getCode(): string
    {
        return SocialLogin::PROVIDER_GOOGLE;
    }

    public function getLabel(): string
    {
        return 'Google';
    }

    public function getCredentialFieldNames(): array
    {
        return ['google_client_id', 'google_client_secret'];
    }

    public function getSecretFieldNames(): array
    {
        return ['google_client_secret'];
    }

    protected function getScopes(): array
    {
        return self::SCOPES;
    }

    protected function toVerifiedIdentity(ResourceOwnerInterface $user): VerifiedIdentity
    {
        if (!$user instanceof GoogleUser) {
            throw new ProviderCommunicationException($this->getCode());
        }

        $identifier = $user->getId();

        if (!\is_string($identifier) || '' === $identifier) {
            throw new ProviderCommunicationException($this->getCode());
        }

        return new VerifiedIdentity(
            provider: $this->getCode(),
            identifier: $identifier,
            email: $user->getEmail(),
            emailVerified: true === $user->getEmailVerified(),
            firstName: $user->getFirstName(),
            lastName: $user->getLastName(),
        );
    }

    protected function createClient(string $callbackUrl): Google
    {
        $clientId = $this->configuration->get('google_client_id');
        $clientSecret = $this->configuration->get('google_client_secret');

        if (null === $clientId || null === $clientSecret) {
            throw new ProviderNotConfiguredException($this->getCode());
        }

        return new Google([
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
            'redirectUri' => $callbackUrl,
        ]);
    }
}
