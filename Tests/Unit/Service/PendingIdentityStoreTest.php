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
use SocialLogin\Exception\PendingIdentityNotFoundException;
use SocialLogin\Service\PendingIdentityStore;
use SocialLogin\Service\SignedPayloadCodec;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * PendingIdentityStore has nowhere to put an identity waiting to be attached other than
 * the session: what is exercised here is that it never crashes for want of one, and that
 * the usual put → peek → forget sequence a caller runs behaves as a single conversation
 * rather than a value anyone can keep reading back forever.
 */
final class PendingIdentityStoreTest extends TestCase
{
    private const string SECRET = 'pending-identity-store-test-secret';

    public function testAPutIdentityIsReturnedByPeek(): void
    {
        $store = $this->storeWithSession();
        $identity = $this->identity();

        $store->put($identity);
        $peeked = $store->peek();

        self::assertSame($identity->provider, $peeked->provider);
        self::assertSame($identity->identifier, $peeked->identifier);
        self::assertSame($identity->email, $peeked->email);
    }

    /**
     * peek() reads without spending it: a wrong password on the attach page must not
     * cost the visitor the whole trip back to the provider.
     */
    public function testPeekingTwiceReturnsTheSameIdentityBothTimes(): void
    {
        $store = $this->storeWithSession();
        $store->put($this->identity());

        $store->peek();

        self::assertSame('apple-subject-1', $store->peek()->identifier);
    }

    /**
     * The one thing that actually spends it: once a caller (attach(), cancel()) calls
     * forget(), the identity is gone for good — the "single use" a two-page conversation
     * with one person is meant to be.
     */
    public function testForgetMakesTheIdentityUnavailable(): void
    {
        $store = $this->storeWithSession();
        $store->put($this->identity());

        $store->forget();

        $this->expectException(PendingIdentityNotFoundException::class);

        $store->peek();
    }

    public function testPeekWithNothingEverPutIsRefused(): void
    {
        $store = $this->storeWithSession();

        $this->expectException(PendingIdentityNotFoundException::class);

        $store->peek();
    }

    /**
     * No request at all — the command line, most obviously — must not crash a caller
     * that tries to set an identity aside.
     */
    public function testPutWithNoRequestIsRefusedRatherThanFatal(): void
    {
        $store = new PendingIdentityStore(new RequestStack(), new SignedPayloadCodec(self::SECRET));

        $this->expectException(PendingIdentityNotFoundException::class);

        $store->put($this->identity());
    }

    public function testPeekWithNoRequestIsRefusedRatherThanFatal(): void
    {
        $store = new PendingIdentityStore(new RequestStack(), new SignedPayloadCodec(self::SECRET));

        $this->expectException(PendingIdentityNotFoundException::class);

        $store->peek();
    }

    /**
     * forget() has nothing to undo without a session, and says so by doing nothing
     * rather than by throwing: every caller (attach(), cancel(), a refused callback)
     * calls it unconditionally on the way out.
     */
    public function testForgetWithNoRequestDoesNothing(): void
    {
        $store = new PendingIdentityStore(new RequestStack(), new SignedPayloadCodec(self::SECRET));

        $store->forget();

        $this->addToAssertionCount(1);
    }

    /**
     * A request that never started a session — same shape as a visitor whose browser
     * sends no session cookie yet.
     */
    public function testPutWithARequestThatHasNoSessionIsRefused(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(new Request());
        $store = new PendingIdentityStore($requestStack, new SignedPayloadCodec(self::SECRET));

        $this->expectException(PendingIdentityNotFoundException::class);

        $store->put($this->identity());
    }

    private function storeWithSession(): PendingIdentityStore
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));

        $requestStack = new RequestStack();
        $requestStack->push($request);

        return new PendingIdentityStore($requestStack, new SignedPayloadCodec(self::SECRET));
    }

    private function identity(): VerifiedIdentity
    {
        return new VerifiedIdentity(
            provider: 'apple',
            identifier: 'apple-subject-1',
            email: 'pending@example.com',
            emailVerified: true,
        );
    }
}
