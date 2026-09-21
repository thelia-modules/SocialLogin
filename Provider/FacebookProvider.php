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

use League\OAuth2\Client\Provider\Facebook;
use League\OAuth2\Client\Provider\FacebookUser;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use SocialLogin\DTO\VerifiedIdentity;
use SocialLogin\Exception\ProviderCommunicationException;
use SocialLogin\Exception\ProviderNotConfiguredException;
use SocialLogin\SocialLogin;

/**
 * Sign in with Facebook, over the Graph API.
 *
 * What the returned identity rests on: the authorization code is exchanged at
 * graph.facebook.com over TLS with the shop's app secret, and the profile is read from
 * `/me` over TLS, signed with `appsecret_proof` — an HMAC of the access token under the
 * app secret, which the library adds and which stops a stolen token from being used
 * from anywhere but this shop.
 *
 * On the address: unlike Google and Apple, Facebook publishes no `email_verified`. What
 * it documents instead is that the `email` field is only returned for an account whose
 * address is confirmed and still valid — an address the person confirmed by reading it,
 * an unreturned field otherwise. That is the whole basis on which an address from
 * Facebook is treated as verified here, and it is weaker than a signed claim: it is a
 * property of the endpoint's behaviour, not something this module can check in the
 * response. It is bounded by the fact that a verified address never signs anybody in to
 * an account on its own — matching is on (provider, identifier) only — so the worst it
 * buys is a new account opened on an address whose owner did not ask for it.
 *
 * The exchange itself is {@see AbstractLeagueOAuth2Provider}'s; what is here is what is
 * Facebook's own.
 */
final readonly class FacebookProvider extends AbstractLeagueOAuth2Provider
{
    /**
     * Pinned, not read from configuration: the version decides the shape of the response
     * this class parses, so it moves when this class is reviewed against a newer one.
     */
    private const string GRAPH_API_VERSION = 'v21.0';

    private const array SCOPES = ['public_profile', 'email'];

    /** Only what an account is opened with — no picture, no age range, no hometown. */
    private const array PROFILE_FIELDS = ['id', 'first_name', 'last_name', 'email'];

    public function getCode(): string
    {
        return SocialLogin::PROVIDER_FACEBOOK;
    }

    public function getLabel(): string
    {
        return 'Facebook';
    }

    public function getCredentialFieldNames(): array
    {
        return ['facebook_client_id', 'facebook_client_secret'];
    }

    public function getSecretFieldNames(): array
    {
        return ['facebook_client_secret'];
    }

    protected function getScopes(): array
    {
        return self::SCOPES;
    }

    protected function toVerifiedIdentity(ResourceOwnerInterface $user): VerifiedIdentity
    {
        if (!$user instanceof FacebookUser) {
            throw new ProviderCommunicationException($this->getCode());
        }

        $identifier = $user->getId();

        if (null === $identifier || '' === $identifier) {
            throw new ProviderCommunicationException($this->getCode());
        }

        $email = $user->getEmail();

        return new VerifiedIdentity(
            provider: $this->getCode(),
            identifier: $identifier,
            email: $email,
            emailVerified: null !== $email && '' !== $email,
            firstName: $user->getFirstName(),
            lastName: $user->getLastName(),
        );
    }

    protected function createClient(string $callbackUrl): Facebook
    {
        $clientId = $this->configuration->get('facebook_client_id');
        $clientSecret = $this->configuration->get('facebook_client_secret');

        if (null === $clientId || null === $clientSecret) {
            throw new ProviderNotConfiguredException($this->getCode());
        }

        return new Facebook([
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
            'redirectUri' => $callbackUrl,
            'graphApiVersion' => self::GRAPH_API_VERSION,
            'fields' => self::PROFILE_FIELDS,
        ]);
    }
}
