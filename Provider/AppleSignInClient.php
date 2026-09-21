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

use Lcobucci\JWT\Signer\Key;
use Lcobucci\JWT\Signer\Key\InMemory;
use League\OAuth2\Client\Grant\AbstractGrant;
use League\OAuth2\Client\Provider\Apple;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Token\AccessTokenInterface;

/**
 * The league Apple provider, with the two things it does that a shop must not.
 *
 * It reads the signing key from a file: `Apple::getLocalKey()` calls `InMemory::file()`
 * on a path. The shop stores the .p8 in its module configuration, and writing a private
 * key out to disk on every sign-in to hand it back a path would put the key somewhere
 * it can be read from, backed up and forgotten. The key is handed over in memory
 * instead; `keyFilePath` is still passed because the parent constructor demands a
 * non-empty one, and nothing ever opens it.
 *
 * It reads `$_GET` and `$_POST` directly in `fetchResourceOwnerDetails()`. Nothing here
 * calls `getResourceOwner()` — the name arrives in the callback body and is read from
 * the parsed request — but the method is neutralised rather than left as a way for a
 * superglobal to reach a decision.
 *
 * `createAccessToken()` is narrowed to a plain token on purpose. The parent builds an
 * `AppleAccessToken`, which fetches Apple's JWKS on every exchange and then verifies the
 * identity token only when the response happens to carry a `refresh_token`, without ever
 * checking its issuer or audience. {@see AppleIdentityTokenVerifier} does that work
 * unconditionally, against a cached key set.
 */
final class AppleSignInClient extends Apple
{
    /** Filled by the parent's mass assignment from the `privateKeyContent` option. */
    protected string $privateKeyContent = '';

    public function getLocalKey(): Key
    {
        if ('' === $this->privateKeyContent) {
            throw new \LogicException('The Apple signing key was not handed to the client.');
        }

        return InMemory::plainText($this->privateKeyContent);
    }

    /**
     * @param array<string, mixed> $response
     */
    protected function createAccessToken(array $response, AbstractGrant $grant): AccessTokenInterface
    {
        return new AccessToken($response);
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchResourceOwnerDetails(AccessToken $token): array
    {
        return [];
    }
}
