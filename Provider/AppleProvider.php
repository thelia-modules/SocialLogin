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

use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessTokenInterface;
use SocialLogin\DTO\VerifiedIdentity;
use SocialLogin\Exception\IdentityTokenVerificationException;
use SocialLogin\Exception\ProviderCommunicationException;
use SocialLogin\Exception\ProviderNotConfiguredException;
use SocialLogin\Service\SocialLoginConfiguration;
use SocialLogin\SocialLogin;

/**
 * Sign in with Apple.
 *
 * Three things set it apart from the other two, and each shapes the code below.
 *
 * The client secret is not a stored string: it is an ES256 JWT the shop signs, per
 * exchange, with its .p8 key, issued by the team id, about the service id, for
 * appleid.apple.com. {@see \League\OAuth2\Client\Provider\Apple::getAccessToken()} mints
 * it; {@see AppleSignInClient} is what lets it do so from a key held in configuration
 * rather than from a file on disk.
 *
 * The callback is an HTTP POST, not a redirect: asking for `name` or `email` makes Apple
 * use `response_mode=form_post`, so everything arrives in the request body and, being
 * cross-site, arrives without this site's `SameSite=Lax` cookies. That is why
 * {@see \SocialLogin\Service\StateManager} does not keep the expected value in the
 * session.
 *
 * The name is given once, and only once: Apple includes it in the `user` field of that
 * first POST, after the very first authorisation, and never again — not in the identity
 * token, not on any later sign-in. It is read here so that a first sign-in can open an
 * account with a real name; every later one falls back to what
 * {@see \SocialLogin\Service\SocialLoginService} does without one.
 */
final readonly class AppleProvider implements SocialLoginProviderInterface
{
    use ProviderConfigurationKeys;

    private const array SCOPES = ['name', 'email'];

    public function __construct(
        private SocialLoginConfiguration $configuration,
        private AppleIdentityTokenVerifier $identityTokenVerifier,
    ) {
    }

    public function getCode(): string
    {
        return SocialLogin::PROVIDER_APPLE;
    }

    public function getLabel(): string
    {
        return 'Apple';
    }

    public function getCredentialFieldNames(): array
    {
        return ['apple_client_id', 'apple_team_id', 'apple_key_id', 'apple_private_key'];
    }

    public function getSecretFieldNames(): array
    {
        return ['apple_private_key'];
    }

    public function getAuthorizationUrl(string $state, string $callbackUrl): string
    {
        return $this->createClient($callbackUrl)->getAuthorizationUrl([
            'state' => $state,
            'scope' => self::SCOPES,
        ]);
    }

    public function fetchVerifiedIdentity(array $callbackParameters, string $callbackUrl): VerifiedIdentity
    {
        $code = $callbackParameters['code'] ?? null;

        if (!\is_string($code) || '' === $code) {
            throw new ProviderCommunicationException($this->getCode());
        }

        $clientId = $this->configuration->get('apple_client_id');

        if (null === $clientId) {
            throw new ProviderNotConfiguredException($this->getCode());
        }

        try {
            // $clientId is handed over rather than re-read: createClient() already needs
            // it to build the client, and reading it a second time here would only be a
            // second chance for it to disagree with the first.
            $token = $this->createClient($callbackUrl, $clientId)->getAccessToken('authorization_code', ['code' => $code]);
        } catch (\InvalidArgumentException $exception) {
            // The client secret is an ES256 JWT signed here with the shop's .p8 key. A key
            // the signer cannot load or parse is rejected with an InvalidArgumentException
            // before any request leaves — a configuration fault, not a failed exchange, so
            // it is classified as one rather than as a provider that would not answer. The
            // cause is carried so the log can name the parse failure without exposing it.
            throw new ProviderNotConfiguredException($this->getCode(), $exception);
        } catch (IdentityProviderException|\UnexpectedValueException $exception) {
            throw new ProviderCommunicationException($this->getCode(), $exception);
        }

        $claims = $this->identityTokenVerifier->verify($this->identityTokenOf($token), $clientId);

        // Already guaranteed a non-empty string by AppleIdentityTokenVerifier::verify(),
        // which throws IdentityTokenVerificationException('subject') otherwise: nothing
        // reaches this line without having passed that check.
        $subject = (string) $claims['sub'];

        [$firstName, $lastName] = $this->nameFromFirstAuthorization($callbackParameters);

        $email = $claims['email'] ?? null;

        return new VerifiedIdentity(
            provider: $this->getCode(),
            identifier: $subject,
            // A relay address (@privaterelay.appleid.com) is a real, deliverable address
            // Apple forwards; it is accepted as any other.
            email: \is_string($email) ? $email : null,
            emailVerified: $this->isEmailVerified($claims),
            firstName: $firstName,
            lastName: $lastName,
        );
    }

    /**
     * @throws IdentityTokenVerificationException
     */
    private function identityTokenOf(AccessTokenInterface $token): string
    {
        $identityToken = $token->getValues()['id_token'] ?? null;

        if (!\is_string($identityToken) || '' === $identityToken) {
            throw new IdentityTokenVerificationException($this->getCode(), 'missing');
        }

        return $identityToken;
    }

    /**
     * Apple sends the claim as a JSON boolean on some accounts and as the string "true"
     * on others, and has done both for years.
     *
     * @param array<string, mixed> $claims
     */
    private function isEmailVerified(array $claims): bool
    {
        $verified = $claims['email_verified'] ?? null;

        return true === $verified || 'true' === $verified;
    }

    /**
     * The `user` field is the one thing in this callback Apple does not sign: the browser
     * posts it, and a browser is where a value gets edited. Nothing here is believed
     * beyond its shape, and what is kept is cut to what the column holds — a name of
     * unbounded length is a write that either fails or is truncated by the database, and
     * neither belongs at the end of a sign-in.
     *
     * @param array<string, mixed> $callbackParameters
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function nameFromFirstAuthorization(array $callbackParameters): array
    {
        $user = $callbackParameters['user'] ?? null;

        if (!\is_string($user) || '' === $user) {
            return [null, null];
        }

        $decoded = json_decode($user, true);

        if (!\is_array($decoded) || !\is_array($decoded['name'] ?? null)) {
            return [null, null];
        }

        return [
            $this->boundedName($decoded['name']['firstName'] ?? null),
            $this->boundedName($decoded['name']['lastName'] ?? null),
        ];
    }

    protected function getConfiguration(): SocialLoginConfiguration
    {
        return $this->configuration;
    }

    private function boundedName(mixed $name): ?string
    {
        if (!\is_string($name)) {
            return null;
        }

        $trimmed = mb_substr(trim($name), 0, VerifiedIdentity::NAME_MAXIMUM_LENGTH);

        return '' === $trimmed ? null : $trimmed;
    }

    /**
     * @param string|null $clientId already read and validated by the caller, for the one
     *                              path ({@see fetchVerifiedIdentity()}) that also needs
     *                              it for something else — {@see getAuthorizationUrl()}
     *                              has no other use for it, so it lets this method read
     *                              it here instead
     */
    private function createClient(string $callbackUrl, ?string $clientId = null): AppleSignInClient
    {
        $clientId ??= $this->configuration->get('apple_client_id');
        $teamId = $this->configuration->get('apple_team_id');
        $keyId = $this->configuration->get('apple_key_id');
        $privateKey = $this->configuration->get('apple_private_key');

        if (null === $clientId || null === $teamId || null === $keyId || null === $privateKey) {
            throw new ProviderNotConfiguredException($this->getCode());
        }

        return new AppleSignInClient([
            'clientId' => $clientId,
            'teamId' => $teamId,
            'keyFileId' => $keyId,
            // Never opened: {@see AppleSignInClient::getLocalKey()} supplies the key from
            // `privateKeyContent`. The parent constructor only refuses an empty one.
            'keyFilePath' => 'in-memory',
            'privateKeyContent' => $privateKey,
            'redirectUri' => $callbackUrl,
        ]);
    }
}
