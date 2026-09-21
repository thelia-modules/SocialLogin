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

namespace SocialLogin\Tests\Integration\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use SocialLogin\Provider\ProviderRegistry;
use SocialLogin\Service\SocialLoginConfiguration;
use SocialLogin\SocialLogin;
use Thelia\Model\ModuleConfigQuery;
use Thelia\Test\IntegrationTestCase;
use Twig\Markup;

/**
 * What the settings reader does with what is actually in ModuleConfig. The reader goes
 * through the module's static accessors rather than an injected store, so the real one is
 * written to: each test runs inside the transaction {@see IntegrationTestCase} opens and
 * rolls back, which puts the rows back as they were, and the per-process cache that
 * {@see ModuleConfigQuery} keeps in front of them, which a rollback does not reach, is
 * emptied after every test.
 *
 * The sanitization itself is not retested here; it belongs to
 * {@see \SocialLogin\Service\SvgLogoSanitizer} and is exercised on its own. What is proved
 * here is the composition around it: read, refuse an empty setting, hand the rest over,
 * and whether a provider counts as configured.
 */
final class SocialLoginConfigurationTest extends IntegrationTestCase
{
    private const string LOGO_KEY = 'google_logo_svg';

    protected function tearDown(): void
    {
        parent::tearDown();

        ModuleConfigQuery::resetConfigCache();
    }

    public function testASettingSavedBlankCountsAsNoLogoAtAll(): void
    {
        SocialLogin::setConfigValue(self::LOGO_KEY, '   ');

        self::assertNull($this->configuration()->getCustomLogo(self::LOGO_KEY));
    }

    public function testASettingNeverFilledInCountsAsNoLogoAtAll(): void
    {
        SocialLogin::setConfigValue(self::LOGO_KEY, '');

        self::assertNull($this->configuration()->getCustomLogo(self::LOGO_KEY));
    }

    public function testAStoredLogoComesBackAsTwigMarkup(): void
    {
        SocialLogin::setConfigValue(self::LOGO_KEY, '<svg viewBox="0 0 24 24"><path d="M12 2 2 22h20z"/></svg>');

        $logo = $this->configuration()->getCustomLogo(self::LOGO_KEY);

        self::assertInstanceOf(Markup::class, $logo);
        self::assertStringContainsStringIgnoringCase('<path', (string) $logo);
    }

    public function testAProviderWithTheSwitchOnButACredentialMissingIsNotConfigured(): void
    {
        SocialLogin::setConfigValue('google_enabled', '1');
        SocialLogin::setConfigValue('google_client_id', 'a-client-id');
        SocialLogin::setConfigValue('google_client_secret', '');

        self::assertFalse($this->googleProvider()->isConfigured());
    }

    public function testAProviderWithEveryCredentialButTheSwitchOffIsNotConfigured(): void
    {
        SocialLogin::setConfigValue('google_enabled', '0');
        SocialLogin::setConfigValue('google_client_id', 'a-client-id');
        SocialLogin::setConfigValue('google_client_secret', 'a-client-secret');

        self::assertFalse($this->googleProvider()->isConfigured());
    }

    /**
     * The logo is not a credential: a provider with none is still offered on the storefront.
     */
    public function testAProviderWithEveryCredentialAndNoLogoIsConfigured(): void
    {
        SocialLogin::setConfigValue('google_enabled', '1');
        SocialLogin::setConfigValue('google_client_id', 'a-client-id');
        SocialLogin::setConfigValue('google_client_secret', 'a-client-secret');
        SocialLogin::setConfigValue(self::LOGO_KEY, '');

        self::assertTrue($this->googleProvider()->isConfigured());
    }

    /**
     * @return iterable<string, array{string, int|null}>
     */
    public static function storedPosition(): iterable
    {
        yield 'a whole number' => ['3', 3];
        yield 'zero' => ['0', 0];
        yield 'surrounded by spaces' => [' 7 ', 7];
        yield 'blank' => ['', null];
        yield 'a word' => ['first', null];
        yield 'a negative number' => ['-1', null];
        yield 'a decimal' => ['1.5', null];
    }

    /**
     * Zero is a real position, not an unset one: ModuleConfig's '0' is the value most
     * easily mistaken for absent.
     */
    #[DataProvider('storedPosition')]
    public function testAPositionReadsAsAWholeNumberOrAsUnset(string $storedValue, ?int $expectedPosition): void
    {
        SocialLogin::setConfigValue('google_position', $storedValue);

        self::assertSame($expectedPosition, $this->configuration()->getPosition('google_position'));
    }

    public function testAProviderWithEveryCredentialAndNoPositionIsConfigured(): void
    {
        SocialLogin::setConfigValue('google_enabled', '1');
        SocialLogin::setConfigValue('google_client_id', 'a-client-id');
        SocialLogin::setConfigValue('google_client_secret', 'a-client-secret');
        SocialLogin::setConfigValue('google_position', '');

        self::assertTrue($this->googleProvider()->isConfigured());
    }

    private function configuration(): SocialLoginConfiguration
    {
        return $this->getService(SocialLoginConfiguration::class);
    }

    private function googleProvider(): \SocialLogin\Provider\SocialLoginProviderInterface
    {
        return $this->getService(ProviderRegistry::class)->getAllProviders()[SocialLogin::PROVIDER_GOOGLE];
    }
}
