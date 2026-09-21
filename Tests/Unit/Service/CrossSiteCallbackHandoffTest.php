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
use SocialLogin\DTO\VerifiedIdentity;
use SocialLogin\Exception\InvalidStateException;
use SocialLogin\Service\CrossSiteCallbackHandoff;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * The handoff carries a verified identity from Apple's cross-site POST to a same-site GET
 * without ever finishing the sign-in on the POST. What is exercised here is the binding
 * that makes the key in the URL insufficient on its own: only the browser that made the
 * POST — the one the binding secret was set as a cookie on — can complete `finish`. A key
 * forwarded to a third party reaches an entry it cannot open.
 *
 * Each rejection asserts its own `reason` rather than only the exception class: the guards
 * sit one after another, and a call refused by the wrong one for the wrong reason would
 * still look like a refusal without it.
 */
final class CrossSiteCallbackHandoffTest extends TestCase
{
    public function testAKeyConsumedWithItsBindingSecretReturnsTheIdentity(): void
    {
        $handoff = $this->handoff();
        $ticket = $handoff->put($this->identity());

        $identity = $handoff->consume($ticket['key'], $ticket['bindingSecret']);

        self::assertSame('apple', $identity->provider);
        self::assertSame('apple-subject-001', $identity->identifier);
        self::assertSame('buyer@example.com', $identity->email);
        self::assertTrue($identity->emailVerified);
        self::assertSame('Ada', $identity->firstName);
        self::assertSame('Lovelace', $identity->lastName);
    }

    public function testAKeyPresentedWithoutTheCookieIsRefused(): void
    {
        $handoff = $this->handoff();
        $ticket = $handoff->put($this->identity());

        self::assertSame(
            'handoff_unbound',
            $this->rejectionReason(static fn () => $handoff->consume($ticket['key'], null)),
        );
    }

    public function testAKeyPresentedWithTheWrongSecretIsRefused(): void
    {
        $handoff = $this->handoff();
        $ticket = $handoff->put($this->identity());

        self::assertSame(
            'handoff_unbound',
            $this->rejectionReason(static fn () => $handoff->consume($ticket['key'], bin2hex(random_bytes(32)))),
        );
    }

    public function testAnUnknownKeyIsRefused(): void
    {
        $handoff = $this->handoff();

        self::assertSame(
            'handoff_unknown',
            $this->rejectionReason(static fn () => $handoff->consume(str_repeat('a', 64), 'irrelevant')),
        );
    }

    public function testAMalformedKeyIsRefused(): void
    {
        $handoff = $this->handoff();

        self::assertSame(
            'handoff_malformed',
            $this->rejectionReason(static fn () => $handoff->consume('not-a-64-hex-key', 'irrelevant')),
        );
    }

    /**
     * The entry is deleted as it is read, whatever the outcome: a key left in history or a
     * referrer cannot be walked back into a second sign-in.
     */
    public function testAKeyIsSingleUse(): void
    {
        $handoff = $this->handoff();
        $ticket = $handoff->put($this->identity());

        $handoff->consume($ticket['key'], $ticket['bindingSecret']);

        self::assertSame(
            'handoff_unknown',
            $this->rejectionReason(static fn () => $handoff->consume($ticket['key'], $ticket['bindingSecret'])),
        );
    }

    /**
     * The read and the delete run under a lock consume() must not leave held: a leaked lock
     * would block the very next handoff on the same key. The handoff and this test share one
     * lock factory over one in-memory store, so a non-blocking re-acquire of the resource
     * consume() locks — keyed on the handoff key — succeeds only if the guarded block let it
     * go.
     */
    public function testTheHandoffLockIsReleasedOnceConsumed(): void
    {
        $lockFactory = self::lockFactory();
        $handoff = new CrossSiteCallbackHandoff(new ArrayAdapter(), $lockFactory);
        $ticket = $handoff->put($this->identity());

        $handoff->consume($ticket['key'], $ticket['bindingSecret']);

        self::assertTrue(
            $lockFactory->createLock('sociallogin-handoff-'.$ticket['key'])->acquire(false),
            'consume() must not leave the handoff lock held.',
        );
    }

    private function handoff(): CrossSiteCallbackHandoff
    {
        return new CrossSiteCallbackHandoff(new ArrayAdapter(), self::lockFactory());
    }

    private function identity(): VerifiedIdentity
    {
        return new VerifiedIdentity(
            provider: 'apple',
            identifier: 'apple-subject-001',
            email: 'buyer@example.com',
            emailVerified: true,
            firstName: 'Ada',
            lastName: 'Lovelace',
        );
    }

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
}
