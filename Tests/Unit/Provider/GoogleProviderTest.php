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

use League\OAuth2\Client\Provider\FacebookUser;
use League\OAuth2\Client\Provider\GoogleUser;
use PHPUnit\Framework\TestCase;
use SocialLogin\DTO\VerifiedIdentity;
use SocialLogin\Exception\ProviderCommunicationException;
use SocialLogin\Provider\GoogleProvider;
use SocialLogin\Service\SocialLoginConfiguration;
use SocialLogin\Service\SvgLogoSanitizer;

/**
 * GoogleProvider::toVerifiedIdentity() is exercised directly, on a {@see GoogleUser}
 * built from a plain array exactly as the league client would build one from Google's
 * userinfo response — no HTTP call, no access token, the mapping is the only thing under
 * test. `email_verified` is Google's own claim, read as-is: the provider adds nothing to
 * it and subtracts nothing from it.
 */
final class GoogleProviderTest extends TestCase
{
    public function testAVerifiedProfileMapsToAVerifiedIdentity(): void
    {
        $identity = $this->toVerifiedIdentity([
            'sub' => 'google-subject-1',
            'email' => 'ada@example.com',
            'email_verified' => true,
            'given_name' => 'Ada',
            'family_name' => 'Lovelace',
        ]);

        self::assertSame('google', $identity->provider);
        self::assertSame('google-subject-1', $identity->identifier);
        self::assertSame('ada@example.com', $identity->email);
        self::assertTrue($identity->emailVerified);
        self::assertSame('Ada', $identity->firstName);
        self::assertSame('Lovelace', $identity->lastName);
    }

    public function testAnUnverifiedEmailIsCarriedAsUnverified(): void
    {
        $identity = $this->toVerifiedIdentity([
            'sub' => 'google-subject-2',
            'email' => 'ada@example.com',
            'email_verified' => false,
        ]);

        self::assertFalse($identity->emailVerified);
        self::assertFalse($identity->hasVerifiedEmail());
    }

    /**
     * Google does not always send the claim; a response that omits it must not be read
     * as verified by accident.
     */
    public function testAMissingEmailVerifiedClaimIsTreatedAsUnverified(): void
    {
        $identity = $this->toVerifiedIdentity([
            'sub' => 'google-subject-3',
            'email' => 'ada@example.com',
        ]);

        self::assertFalse($identity->emailVerified);
    }

    public function testAnEmptyIdentifierIsRefused(): void
    {
        $this->expectException(ProviderCommunicationException::class);

        $this->toVerifiedIdentity(['sub' => '']);
    }

    /**
     * The abstract exchange calls this only with the resource owner its own provider
     * returned; a differently-shaped one reaching it regardless is refused rather than
     * read as if it were a GoogleUser.
     */
    public function testAResourceOwnerOfTheWrongShapeIsRefused(): void
    {
        $this->expectException(ProviderCommunicationException::class);

        $method = new \ReflectionMethod(GoogleProvider::class, 'toVerifiedIdentity');
        $method->invoke($this->provider(), new FacebookUser(['id' => 'x']));
    }

    /**
     * @param array<string, mixed> $response
     */
    private function toVerifiedIdentity(array $response): VerifiedIdentity
    {
        $method = new \ReflectionMethod(GoogleProvider::class, 'toVerifiedIdentity');

        return $method->invoke($this->provider(), new GoogleUser($response));
    }

    private function provider(): GoogleProvider
    {
        return new GoogleProvider(new SocialLoginConfiguration(new SvgLogoSanitizer()));
    }
}
