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

namespace SocialLogin\Tests\Integration\Controller;

use SocialLogin\SocialLogin;
use Symfony\Component\DomCrawler\Crawler;
use Thelia\Model\ModuleConfigQuery;
use Thelia\Test\WebIntegrationTestCase;

/**
 * The buttons a visitor sees on the login page, in the order the merchant chose, read
 * from the page the storefront actually renders.
 */
final class LoginPageProviderOrderTest extends WebIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([SocialLogin::PROVIDER_GOOGLE, SocialLogin::PROVIDER_FACEBOOK] as $code) {
            SocialLogin::setConfigValue($code.'_enabled', '1');
            SocialLogin::setConfigValue($code.'_client_id', 'test-client-id');
            SocialLogin::setConfigValue($code.'_client_secret', 'test-client-secret');
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        ModuleConfigQuery::resetConfigCache();
    }

    public function testTheButtonsFollowTheSavedPositions(): void
    {
        SocialLogin::setConfigValue('google_position', '2');
        SocialLogin::setConfigValue('facebook_position', '1');

        self::assertSame(['facebook', 'google'], $this->buttonOrder());
    }

    public function testTheButtonsFollowTheSavedPositionsTheOtherWayRound(): void
    {
        SocialLogin::setConfigValue('google_position', '1');
        SocialLogin::setConfigValue('facebook_position', '2');

        self::assertSame(['google', 'facebook'], $this->buttonOrder());
    }

    /**
     * @return list<string> provider codes, in the order their buttons appear on the page
     */
    private function buttonOrder(): array
    {
        $this->client->catchExceptions(false);
        $page = $this->client->request('GET', '/customer/login');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return $page
            ->filter('a[href*="/social-login/start/"]')
            ->each(static fn (Crawler $link): string => (string) preg_replace('#^.*/social-login/start/([a-z]+).*$#', '$1', (string) $link->attr('href')));
    }
}
