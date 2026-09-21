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

namespace SocialLogin\Tests\Unit\Provider;

use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use SocialLogin\Exception\IdentityTokenVerificationException;
use SocialLogin\Provider\AppleIdentityTokenVerifier;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Apple's identity token carries the whole identity, so every claim checked here is
 * load-bearing rather than belt-and-braces (see the class docblock). Each test builds
 * its own key pair and its own JWKS response: nothing here is Apple's real key, only a
 * token shaped exactly like one, over a locally generated ES256 pair.
 */
final class AppleIdentityTokenVerifierTest extends TestCase
{
    private const string ISSUER = 'https://appleid.apple.com';
    private const string AUDIENCE = 'com.example.shop';
    private const string KEY_ID = 'test-key-1';

    private string $privateKeyPem;

    /** @var array<string, mixed> */
    private array $jsonWebKey;

    protected function setUp(): void
    {
        [$this->privateKeyPem, $this->jsonWebKey] = self::generateEcKeyPair(self::KEY_ID);
    }

    public function testAValidTokenIsAccepted(): void
    {
        $token = $this->signToken([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'sub' => 'apple-subject-1',
            'iat' => time(),
            'exp' => time() + 300,
        ]);

        $claims = $this->verifierFor($this->jsonWebKey)->verify($token, self::AUDIENCE);

        self::assertSame('apple-subject-1', $claims['sub']);
    }

    public function testATokenSignedWithAnotherKeyIsRejected(): void
    {
        // Same key id as the one published in the JWKS, but signed with a key nobody
        // published: exactly what a forged token looks like from here.
        [$forgedPrivateKeyPem] = self::generateEcKeyPair(self::KEY_ID);
        $token = $this->signToken(
            ['iss' => self::ISSUER, 'aud' => self::AUDIENCE, 'sub' => 'x', 'exp' => time() + 300],
            $forgedPrivateKeyPem,
        );

        $exception = $this->expectRejection($token);

        self::assertSame('signature', $exception->reason);
    }

    public function testATokenFromAnotherIssuerIsRejected(): void
    {
        $token = $this->signToken([
            'iss' => 'https://not-apple.example.com',
            'aud' => self::AUDIENCE,
            'sub' => 'x',
            'exp' => time() + 300,
        ]);

        self::assertSame('issuer', $this->expectRejection($token)->reason);
    }

    public function testATokenForAnotherAudienceIsRejected(): void
    {
        $token = $this->signToken([
            'iss' => self::ISSUER,
            'aud' => 'com.example.someone-elses-app',
            'sub' => 'x',
            'exp' => time() + 300,
        ]);

        self::assertSame('audience', $this->expectRejection($token)->reason);
    }

    public function testAnExpiredTokenIsRejected(): void
    {
        $token = $this->signToken([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'sub' => 'x',
            'iat' => time() - 600,
            'exp' => time() - 300,
        ]);

        self::assertSame('lifetime', $this->expectRejection($token)->reason);
    }

    /**
     * exp is optional as far as the JWT library is concerned — it is only checked when
     * present. Apple always sends one, but nothing stops a forged token from leaving it
     * out to dodge the expiry check entirely, which is exactly why the verifier makes it
     * mandatory on its own.
     */
    public function testATokenWithNoExpiryClaimIsRejected(): void
    {
        $token = $this->signToken([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'sub' => 'x',
        ]);

        self::assertSame('expiry', $this->expectRejection($token)->reason);
    }

    public function testATokenClaimingAlgNoneIsRejected(): void
    {
        $header = self::toBase64Url((string) json_encode(['typ' => 'JWT', 'alg' => 'none'], \JSON_THROW_ON_ERROR));
        $payload = self::toBase64Url((string) json_encode([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'sub' => 'x',
            'exp' => time() + 300,
        ], \JSON_THROW_ON_ERROR));
        $token = $header.'.'.$payload.'.';

        self::assertSame('signature', $this->expectRejection($token)->reason);
    }

    private function expectRejection(string $token): IdentityTokenVerificationException
    {
        try {
            $this->verifierFor($this->jsonWebKey)->verify($token, self::AUDIENCE);
        } catch (IdentityTokenVerificationException $exception) {
            return $exception;
        }

        self::fail('Expected an IdentityTokenVerificationException to be thrown.');
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function signToken(array $claims, ?string $privateKeyPem = null): string
    {
        return JWT::encode($claims, $privateKeyPem ?? $this->privateKeyPem, 'ES256', self::KEY_ID);
    }

    /**
     * @param array<string, mixed> $jsonWebKey
     */
    private function verifierFor(array $jsonWebKey): AppleIdentityTokenVerifier
    {
        $httpClient = new MockHttpClient([
            new MockResponse((string) json_encode(['keys' => [$jsonWebKey]], \JSON_THROW_ON_ERROR)),
        ]);

        return new AppleIdentityTokenVerifier($httpClient, new ArrayAdapter());
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function generateEcKeyPair(string $keyId): array
    {
        $resource = openssl_pkey_new([
            'private_key_type' => \OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);

        self::assertNotFalse($resource, 'Could not generate a test EC key pair.');

        openssl_pkey_export($resource, $privateKeyPem);
        $details = openssl_pkey_get_details($resource);

        if (!\is_array($details) || !\is_array($details['ec'] ?? null)) {
            throw new \RuntimeException('Could not read the details of the generated EC key pair.');
        }

        // P-256 coordinates are 32 bytes; openssl strips leading zero bytes, so they are
        // padded back before being base64url-encoded, or an unlucky key would produce a
        // JWK the library reads as a different point.
        $x = str_pad((string) $details['ec']['x'], 32, "\0", \STR_PAD_LEFT);
        $y = str_pad((string) $details['ec']['y'], 32, "\0", \STR_PAD_LEFT);

        return [$privateKeyPem, [
            'kty' => 'EC',
            'crv' => 'P-256',
            'alg' => 'ES256',
            'use' => 'sig',
            'kid' => $keyId,
            'x' => self::toBase64Url($x),
            'y' => self::toBase64Url($y),
        ]];
    }

    private static function toBase64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
