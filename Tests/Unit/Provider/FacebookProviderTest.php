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
use SocialLogin\Provider\FacebookProvider;
use SocialLogin\Service\SocialLoginConfiguration;
use SocialLogin\Service\SvgLogoSanitizer;

/**
 * FacebookProvider::toVerifiedIdentity() is exercised directly, on a {@see FacebookUser}
 * built from a plain array exactly as the league client would build one from the Graph
 * `/me` response — no HTTP call. Facebook publishes no `email_verified` claim; what is
 * under test here is the module's own stand-in for it: an `email` field is only ever
 * returned for a confirmed, still-valid address, so its mere presence is read as
 * verified (see the class docblock for the reasoning and its bounds).
 */
final class FacebookProviderTest extends TestCase
{
    public function testAProfileWithAnEmailIsReadAsVerified(): void
    {
        $identity = $this->toVerifiedIdentity([
            'id' => 'facebook-subject-1',
            'email' => 'ada@example.com',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
        ]);

        self::assertSame('facebook', $identity->provider);
        self::assertSame('facebook-subject-1', $identity->identifier);
        self::assertSame('ada@example.com', $identity->email);
        self::assertTrue($identity->emailVerified);
        self::assertSame('Ada', $identity->firstName);
        self::assertSame('Lovelace', $identity->lastName);
    }

    /**
     * No `email` field at all — the profile scope was granted but the address is
     * unconfirmed or was never on the account — reads as unverified, not merely absent.
     */
    public function testAProfileWithNoEmailIsReadAsUnverified(): void
    {
        $identity = $this->toVerifiedIdentity([
            'id' => 'facebook-subject-2',
        ]);

        self::assertNull($identity->email);
        self::assertFalse($identity->emailVerified);
        self::assertFalse($identity->hasVerifiedEmail());
    }

    public function testAnEmptyIdentifierIsRefused(): void
    {
        $this->expectException(ProviderCommunicationException::class);

        $this->toVerifiedIdentity(['id' => '']);
    }

    /**
     * The abstract exchange calls this only with the resource owner its own provider
     * returned; a differently-shaped one reaching it regardless is refused rather than
     * read as if it were a FacebookUser.
     */
    public function testAResourceOwnerOfTheWrongShapeIsRefused(): void
    {
        $this->expectException(ProviderCommunicationException::class);

        $method = new \ReflectionMethod(FacebookProvider::class, 'toVerifiedIdentity');
        $method->invoke($this->provider(), new GoogleUser(['sub' => 'x']));
    }

    /**
     * @param array<string, mixed> $response
     */
    private function toVerifiedIdentity(array $response): VerifiedIdentity
    {
        $method = new \ReflectionMethod(FacebookProvider::class, 'toVerifiedIdentity');

        return $method->invoke($this->provider(), new FacebookUser($response));
    }

    private function provider(): FacebookProvider
    {
        return new FacebookProvider(new SocialLoginConfiguration(new SvgLogoSanitizer()));
    }
}
