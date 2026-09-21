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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SocialLogin\Provider\AppleIdentityTokenVerifier;
use SocialLogin\Provider\AppleProvider;
use SocialLogin\Provider\FacebookProvider;
use SocialLogin\Provider\GoogleProvider;
use SocialLogin\Provider\SocialLoginProviderInterface;
use SocialLogin\Service\SocialLoginConfiguration;
use SocialLogin\Service\SvgLogoSanitizer;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * The contract every provider owes about its own configuration keys, checked on all of
 * them at once rather than on one: the whole point of deriving the switch and the logo
 * from the provider's code, next to the credentials it lists itself, is that no provider
 * can drift from it, and only a test that runs over every implementation can say so.
 *
 * Nothing here reads a setting, so no store is needed: these are statements about names,
 * not about what the shop filled in.
 */
final class ProviderConfigurationKeysTest extends TestCase
{
    /**
     * @return iterable<string, array{SocialLoginProviderInterface}>
     */
    public static function provider(): iterable
    {
        $configuration = new SocialLoginConfiguration(new SvgLogoSanitizer());

        yield 'google' => [new GoogleProvider($configuration)];
        yield 'facebook' => [new FacebookProvider($configuration)];
        yield 'apple' => [new AppleProvider($configuration, new AppleIdentityTokenVerifier(new MockHttpClient(), new ArrayAdapter()))];
    }

    /**
     * A provider with no logo pasted is still fully configured, and the switch is not
     * something obtained from the provider's console: neither may be listed among the
     * credentials, which are exactly what "configured" requires.
     */
    #[DataProvider('provider')]
    public function testNeitherTheSwitchNorTheLogoIsACredential(SocialLoginProviderInterface $provider): void
    {
        self::assertNotEmpty($provider->getCredentialFieldNames());
        self::assertNotContains($provider->getEnabledFieldName(), $provider->getCredentialFieldNames());
        self::assertNotContains($provider->getLogoFieldName(), $provider->getCredentialFieldNames());
    }

    /**
     * The display order only places a provider among the others: it is never something
     * a provider needs to be configured, and never a key already used for something else.
     */
    #[DataProvider('provider')]
    public function testThePositionIsNeitherACredentialNorAnotherKey(SocialLoginProviderInterface $provider): void
    {
        self::assertNotContains($provider->getPositionFieldName(), $provider->getCredentialFieldNames());
        self::assertNotSame($provider->getEnabledFieldName(), $provider->getPositionFieldName());
        self::assertNotSame($provider->getLogoFieldName(), $provider->getPositionFieldName());
    }

    #[DataProvider('provider')]
    public function testEveryCredentialIsNamedAfterTheProviderCode(SocialLoginProviderInterface $provider): void
    {
        foreach ($provider->getCredentialFieldNames() as $credentialFieldName) {
            self::assertStringStartsWith($provider->getCode().'_', $credentialFieldName);
        }
    }

    /**
     * A secret is one of the credentials, never a key of its own: a screen that hides the
     * secrets by intersecting the two lists would otherwise show one in clear.
     */
    #[DataProvider('provider')]
    public function testEverySecretKeyIsACredentialKey(SocialLoginProviderInterface $provider): void
    {
        foreach ($provider->getSecretFieldNames() as $secretFieldName) {
            self::assertContains($secretFieldName, $provider->getCredentialFieldNames());
        }
    }

    #[DataProvider('provider')]
    public function testTheKeysAreNamedAfterTheProviderCode(SocialLoginProviderInterface $provider): void
    {
        self::assertSame($provider->getCode().'_enabled', $provider->getEnabledFieldName());
        self::assertSame($provider->getCode().'_logo_svg', $provider->getLogoFieldName());
        self::assertSame($provider->getCode().'_position', $provider->getPositionFieldName());
    }
}
