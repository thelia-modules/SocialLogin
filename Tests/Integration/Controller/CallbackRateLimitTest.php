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

use SocialLogin\Exception\InvalidStateException;
use SocialLogin\Exception\TooManyAttemptsException;
use SocialLogin\SocialLogin;
use Thelia\Core\Translation\Translator;
use Thelia\Test\WebIntegrationTestCase;

/**
 * The return from a provider answers anyone, so a client address may only knock on it so
 * many times: past the allowance, the callback refuses before it looks at the state.
 *
 * The limiter counts in the shop's security cache, which outlives a test, so every test
 * calls from an address of its own that nothing else has used.
 */
final class CallbackRateLimitTest extends WebIntegrationTestCase
{
    private const int ALLOWANCE = 20;

    public function testACallbackPastTheAllowanceIsRefusedBeforeItsStateIsChecked(): void
    {
        $address = $this->freshAddress();

        for ($call = 1; $call <= self::ALLOWANCE; ++$call) {
            self::assertSame(
                InvalidStateException::TRANSLATION_KEY,
                $this->forgedCallback($address, 'google'),
                \sprintf('Call %d is within the allowance: it reaches the state check.', $call),
            );
        }

        self::assertSame(
            TooManyAttemptsException::TRANSLATION_KEY,
            $this->forgedCallback($address, 'google'),
        );
    }

    public function testAnotherAddressKeepsItsOwnAllowance(): void
    {
        $exhausted = $this->freshAddress();

        for ($call = 0; $call <= self::ALLOWANCE; ++$call) {
            $this->forgedCallback($exhausted, 'google');
        }

        self::assertSame(
            InvalidStateException::TRANSLATION_KEY,
            $this->forgedCallback($this->freshAddress(), 'google'),
        );
    }

    public function testAnotherProviderKeepsItsOwnAllowance(): void
    {
        $address = $this->freshAddress();

        for ($call = 0; $call <= self::ALLOWANCE; ++$call) {
            $this->forgedCallback($address, 'google');
        }

        self::assertSame(
            InvalidStateException::TRANSLATION_KEY,
            $this->forgedCallback($address, 'facebook'),
        );
    }

    /**
     * Calls the callback with a state nobody signed, follows the redirect to the login page
     * and tells which of the two notices that page shows the visitor.
     *
     * @return string the translation key of the notice
     */
    private function forgedCallback(string $address, string $provider): string
    {
        $this->client->catchExceptions(false);
        $this->client->followRedirects(false);
        $this->client->request(
            'GET',
            '/social-login/callback/'.$provider.'?code=x&state=forged',
            server: ['REMOTE_ADDR' => $address],
        );
        self::assertSame(302, $this->client->getResponse()->getStatusCode());

        $page = (string) $this->client->followRedirect()->html();

        $shown = array_values(array_filter(
            [InvalidStateException::TRANSLATION_KEY, TooManyAttemptsException::TRANSLATION_KEY],
            fn (string $key): bool => str_contains($page, htmlspecialchars($this->message($key), \ENT_QUOTES)),
        ));
        self::assertCount(1, $shown, 'The login page must show exactly one of the two notices.');

        return $shown[0];
    }

    private function message(string $translationKey): string
    {
        return Translator::getInstance()->trans($translationKey, [], SocialLogin::DOMAIN_NAME);
    }

    private function freshAddress(): string
    {
        return \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254));
    }
}
