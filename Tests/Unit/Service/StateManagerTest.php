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

namespace SocialLogin\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use SocialLogin\Exception\InvalidStateException;
use SocialLogin\Service\SignedPayloadCodec;
use SocialLogin\Service\StateManager;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * StateManager delegates the signature and the lifetime check to SignedPayloadCodec, so
 * what is exercised here is the pairing rule: a return is accepted only when the `state`
 * and the cookie were minted together, name the provider that is asking, and have not
 * already been spent.
 *
 * Each rejection asserts its own `reason` rather than only the exception class: several
 * guards sit one after another, and disabling one alone can still leave a call rejected
 * by the next one in line, for the wrong reason. Asserting the reason is what tells the
 * guards apart from each other.
 *
 * A few adversarial cases are built with {@see craftState()} rather than through
 * StateManager::start(), because the thing under test — an old or foreign token —
 * is exactly what start() refuses to produce.
 */
final class StateManagerTest extends TestCase
{
    private const string SECRET = 'state-manager-test-secret';
    private const string PURPOSE = 'state';
    private const int LIFETIME_SECONDS = 600;

    private StateManager $stateManager;

    protected function setUp(): void
    {
        // A pool per test, and never shared with the foreign manager below: the single-use
        // rule is now kept in one, so a pool carried between tests would refuse the second
        // test's state for something the first test did.
        $this->stateManager = new StateManager(new SignedPayloadCodec(self::SECRET), new ArrayAdapter(), self::lockFactory());
    }

    public function testAValidStateIsAccepted(): void
    {
        $challenge = $this->stateManager->start('google');

        $this->stateManager->validate('google', $challenge->state, $challenge->cookie->getValue());

        $this->addToAssertionCount(1);
    }

    public function testAMissingStateIsRejected(): void
    {
        $challenge = $this->stateManager->start('google');

        self::assertSame(
            'missing_state',
            $this->rejectionReason(fn () => $this->stateManager->validate('google', null, $challenge->cookie->getValue())),
        );
    }

    public function testAMissingCookieIsRejected(): void
    {
        $challenge = $this->stateManager->start('google');

        self::assertSame(
            'missing_cookie',
            $this->rejectionReason(fn () => $this->stateManager->validate('google', $challenge->state, null)),
        );
    }

    public function testATamperedStateIsRejected(): void
    {
        $challenge = $this->stateManager->start('google');

        // Flipped in the body, not at the very end of the token: the last base64url
        // character of a token only encodes a couple of real bits, and a lenient
        // decode can land on the same byte there for more than one character —
        // flaky ground to tamper on. A character a few positions in is well inside
        // the encoded JSON and any change to it changes the decoded bytes.
        [$body, $signature] = explode('.', $challenge->state);
        $tamperedBody = substr($body, 0, 5).('A' === $body[5] ? 'B' : 'A').substr($body, 6);
        $tampered = $tamperedBody.'.'.$signature;

        self::assertSame(
            'unsigned_or_expired',
            $this->rejectionReason(fn () => $this->stateManager->validate('google', $tampered, $challenge->cookie->getValue())),
        );
    }

    public function testAnExpiredStateIsRejected(): void
    {
        $nonce = 'expired-nonce';
        $state = $this->craftState('google', hash('sha256', $nonce), time() - self::LIFETIME_SECONDS - 1);

        self::assertSame(
            'unsigned_or_expired',
            $this->rejectionReason(fn () => $this->stateManager->validate('google', $state, $nonce)),
        );
    }

    /**
     * The single-use property is not kept by StateManager itself: it comes from the
     * caller clearing the cookie once a return has been validated. A second attempt with
     * the very same `state`, once that cookie is gone, is what a replay looks like from
     * here.
     */
    public function testAStateReplayedWithoutItsCookieIsRejected(): void
    {
        $challenge = $this->stateManager->start('google');

        $this->stateManager->validate('google', $challenge->state, $challenge->cookie->getValue());

        self::assertSame(
            'missing_cookie',
            $this->rejectionReason(fn () => $this->stateManager->validate(
                'google',
                $challenge->state,
                $this->stateManager->clearCookie()->getValue(),
            )),
        );
    }

    /**
     * The cookie is what stops a replay from another browser; it does nothing about one
     * from the same browser, whose jar can be copied and whose clearing response may never
     * have arrived. A nonce spent on the server is refused whatever is presented with it.
     */
    public function testAStateReplayedWithItsOwnCookieIsRejected(): void
    {
        $challenge = $this->stateManager->start('google');

        $this->stateManager->validate('google', $challenge->state, $challenge->cookie->getValue());

        self::assertSame(
            'already_spent',
            $this->rejectionReason(fn () => $this->stateManager->validate('google', $challenge->state, $challenge->cookie->getValue())),
        );
    }

    /**
     * The nonce is read and marked under a lock spend() must not leave held: a leaked lock
     * would deadlock the very next validation, since a return is validated under a blocking
     * acquire of the same resource. The manager and this test share one lock factory over
     * one in-memory store, so a non-blocking re-acquire of the resource spend() locks — the
     * nonce hash the `state` carries — succeeds only if the guarded block let it go.
     *
     * That the second validation below returns `already_spent` at all, rather than hanging,
     * is the other half of the same proof: it could not run under the lock if the first
     * validation had not released it.
     */
    public function testTheStateLockIsReleasedOnceTheNonceIsSpent(): void
    {
        $lockFactory = self::lockFactory();
        $stateManager = new StateManager(new SignedPayloadCodec(self::SECRET), new ArrayAdapter(), $lockFactory);
        $challenge = $stateManager->start('google');
        $cookieValue = $challenge->cookie->getValue();
        self::assertIsString($cookieValue);

        $stateManager->validate('google', $challenge->state, $cookieValue);

        $nonceHash = hash('sha256', $cookieValue);
        self::assertTrue(
            $lockFactory->createLock('sociallogin-state-'.$nonceHash)->acquire(false),
            'spend() must not leave the state lock held.',
        );
    }

    public function testAStateMintedForAnotherProviderIsRejected(): void
    {
        $challenge = $this->stateManager->start('google');

        self::assertSame(
            'provider_mismatch',
            $this->rejectionReason(fn () => $this->stateManager->validate('facebook', $challenge->state, $challenge->cookie->getValue())),
        );
    }

    public function testAStateSignedWithAnotherSecretIsRejected(): void
    {
        $foreignManager = new StateManager(new SignedPayloadCodec('a-completely-different-secret'), new ArrayAdapter(), self::lockFactory());
        $challenge = $foreignManager->start('google');

        self::assertSame(
            'unsigned_or_expired',
            $this->rejectionReason(fn () => $this->stateManager->validate('google', $challenge->state, $challenge->cookie->getValue())),
        );
    }

    /**
     * A real lock over an in-memory store: enough to exercise the guarded sequence in one
     * process. What it holds against a genuine burst is a property of the deployed store.
     */
    private static function lockFactory(): LockFactory
    {
        return new LockFactory(new InMemoryStore());
    }

    private function rejectionReason(callable $call): string
    {
        try {
            $call();
        } catch (InvalidStateException $exception) {
            return $exception->reason;
        }

        self::fail('Expected an InvalidStateException to be thrown.');
    }

    /**
     * Rebuilds a `state` token with an arbitrary mint time, using the same wire format
     * SignedPayloadCodec writes: a base64url JSON body, a dot, and a base64url HMAC of
     * that body keyed on the purpose. There is no other way to produce an old token,
     * since the codec always stamps `time()` on encode().
     */
    private function craftState(string $provider, string $nonceHash, int $issuedAt): string
    {
        $body = $this->toBase64Url((string) json_encode([
            'provider' => $provider,
            'nonce' => $nonceHash,
            'issuedAt' => $issuedAt,
        ], \JSON_THROW_ON_ERROR));

        return $body.'.'.$this->toBase64Url($this->sign($body));
    }

    private function sign(string $body): string
    {
        $key = hash_hmac('sha256', 'sociallogin:'.self::PURPOSE, self::SECRET, true);

        return hash_hmac('sha256', $body, $key, true);
    }

    private function toBase64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
