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

use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use SocialLogin\Exception\IdentityTokenVerificationException;
use SocialLogin\SocialLogin;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Checks an Apple identity token before anything it claims is believed.
 *
 * Apple is the one provider here whose identity travels entirely inside a token: there
 * is no profile endpoint to ask (`Apple::getResourceOwnerDetailsUrl()` throws), so the
 * subject and the address come from the JWT and from nowhere else. That makes all four
 * checks load-bearing rather than belt-and-braces:
 *
 * - the signature, against Apple's published JWKS, or the token is a forgery;
 * - the issuer, or a token minted elsewhere passes;
 * - the audience, or an identity token issued to a *different* Apple application — one
 *   its own developer controls — signs its bearer into this shop;
 * - the expiry, which {@see JWT::decode()} enforces, and whose presence is required here
 *   because that check is skipped for a token that simply omits `exp`.
 *
 * The key set is cached: Apple asks that it not be fetched per request, and a sign-in
 * must not wait on it. It is refetched once when a token names a key the cached set does
 * not hold, which is what a rotation looks like from here and is indistinguishable from
 * a bad signature otherwise.
 */
final readonly class AppleIdentityTokenVerifier
{
    private const string ISSUER = 'https://appleid.apple.com';
    private const string KEYS_URL = 'https://appleid.apple.com/auth/keys';
    private const string KEYS_CACHE_KEY = 'sociallogin.apple.jwks';
    private const int KEYS_CACHE_LIFETIME = 43200;

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire(service: 'thelia.cache.security')]
        private CacheInterface $cache,
    ) {
    }

    /**
     * @return array<string, mixed> the claims, once every one of them can be relied on
     *
     * @throws IdentityTokenVerificationException
     */
    public function verify(string $identityToken, string $audience): array
    {
        $claims = $this->decodeWithRotationRetry($identityToken);

        if (self::ISSUER !== ($claims['iss'] ?? null)) {
            throw new IdentityTokenVerificationException(SocialLogin::PROVIDER_APPLE, 'issuer');
        }

        if (!\in_array($audience, $this->audiencesOf($claims), true)) {
            throw new IdentityTokenVerificationException(SocialLogin::PROVIDER_APPLE, 'audience');
        }

        if (!isset($claims['exp']) || !is_numeric($claims['exp'])) {
            throw new IdentityTokenVerificationException(SocialLogin::PROVIDER_APPLE, 'expiry');
        }

        $subject = $claims['sub'] ?? null;

        if (!\is_string($subject) || '' === $subject) {
            throw new IdentityTokenVerificationException(SocialLogin::PROVIDER_APPLE, 'subject');
        }

        return $claims;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws IdentityTokenVerificationException
     */
    private function decodeWithRotationRetry(string $identityToken): array
    {
        $keySet = $this->keySet(refresh: false);

        // Only a token naming a key the cached set does not hold gets a refetch. Retrying
        // on any failure instead would turn every forged token into a request to Apple.
        if (!$this->holdsKey($keySet, $this->keyIdOf($identityToken))) {
            $keySet = $this->keySet(refresh: true);
        }

        return $this->decode($identityToken, $keySet);
    }

    private function keyIdOf(string $identityToken): ?string
    {
        $segments = explode('.', $identityToken);

        if (3 !== \count($segments)) {
            return null;
        }

        $header = json_decode((string) base64_decode(strtr($segments[0], '-_', '+/'), true), true);

        if (!\is_array($header) || !\is_string($header['kid'] ?? null)) {
            return null;
        }

        return $header['kid'];
    }

    /**
     * @param array<string, mixed> $keySet
     */
    private function holdsKey(array $keySet, ?string $keyId): bool
    {
        if (null === $keyId) {
            return true;
        }

        $keys = $keySet['keys'] ?? [];

        if (!\is_array($keys)) {
            return false;
        }

        foreach ($keys as $key) {
            if (\is_array($key) && $keyId === ($key['kid'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $jsonWebKeySet
     *
     * @return array<string, mixed>
     *
     * @throws IdentityTokenVerificationException
     */
    private function decode(string $identityToken, array $jsonWebKeySet): array
    {
        try {
            $keys = JWK::parseKeySet($jsonWebKeySet, 'RS256');

            /** @var array<string, mixed> $claims */
            $claims = (array) JWT::decode($identityToken, $keys);

            return $claims;
        } catch (ExpiredException|BeforeValidException $throwable) {
            // Told apart from a bad signature so that the log says which it was: one is a
            // slow visitor or a clock out of step, the other is an attack.
            throw new IdentityTokenVerificationException(SocialLogin::PROVIDER_APPLE, 'lifetime', $throwable);
        } catch (\Throwable $throwable) {
            throw new IdentityTokenVerificationException(SocialLogin::PROVIDER_APPLE, 'signature', $throwable);
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws IdentityTokenVerificationException
     */
    private function keySet(bool $refresh): array
    {
        if ($refresh) {
            $this->cache->delete(self::KEYS_CACHE_KEY);
        }

        try {
            /** @var array<string, mixed> $keySet */
            $keySet = $this->cache->get(self::KEYS_CACHE_KEY, function (ItemInterface $item): array {
                $item->expiresAfter(self::KEYS_CACHE_LIFETIME);

                $response = $this->httpClient->request('GET', self::KEYS_URL);

                /** @var array<string, mixed> $keySet */
                $keySet = $response->toArray();

                if ([] === ($keySet['keys'] ?? [])) {
                    throw new \RuntimeException('Apple returned an empty key set.');
                }

                return $keySet;
            });
        } catch (HttpClientExceptionInterface|\RuntimeException $exception) {
            throw new IdentityTokenVerificationException(SocialLogin::PROVIDER_APPLE, 'key_set', $exception);
        }

        return $keySet;
    }

    /**
     * `aud` is a single string for one application and a list for several; both are legal.
     *
     * @param array<string, mixed> $claims
     *
     * @return list<string>
     */
    private function audiencesOf(array $claims): array
    {
        $audience = $claims['aud'] ?? null;

        if (\is_string($audience)) {
            return [$audience];
        }

        if (!\is_array($audience)) {
            return [];
        }

        return array_values(array_filter($audience, \is_string(...)));
    }
}
