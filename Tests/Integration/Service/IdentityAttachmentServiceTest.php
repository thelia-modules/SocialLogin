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

use Psr\Log\NullLogger;
use SocialLogin\DTO\SocialLoginOutcomeStatus;
use SocialLogin\DTO\VerifiedIdentity;
use SocialLogin\Exception\IdentityAlreadyLinkedException;
use SocialLogin\Exception\InvalidPasswordException;
use SocialLogin\Exception\PendingIdentityNotFoundException;
use SocialLogin\Exception\TooManyAttemptsException;
use SocialLogin\Service\IdentityAttachmentService;
use SocialLogin\Service\PasswordAttemptLimiter;
use SocialLogin\Service\PendingIdentityStore;
use SocialLogin\Service\SignedPayloadCodec;
use SocialLogin\Service\SocialLoginIdentityRepository;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Thelia\Domain\Customer\Service\CustomerAuthenticator;
use Thelia\Model\Customer;
use Thelia\Test\IntegrationTestCase;

/**
 * IdentityAttachmentService proves the visitor owns the account an address already
 * belongs to, on that account's own password — a guessing oracle without
 * {@see PasswordAttemptLimiter} in front of it (see the class docblock).
 */
final class IdentityAttachmentServiceTest extends IntegrationTestCase
{
    private SocialLoginIdentityRepository $identities;
    private PendingIdentityStore $pendingIdentities;

    protected function setUp(): void
    {
        parent::setUp();
        $this->identities = new SocialLoginIdentityRepository();
        $this->pendingIdentities = new PendingIdentityStore(
            $this->getService(RequestStack::class),
            new SignedPayloadCodec('identity-attachment-service-test-secret'),
        );
    }

    public function testGetPendingEmailReturnsTheAddressAwaitingConfirmation(): void
    {
        $customer = $this->customerWithPassword();
        $this->pendingIdentities->put($this->pendingIdentityFor($customer));

        self::assertSame($customer->getEmail(), $this->buildService()->getPendingEmail());
    }

    public function testGetPendingEmailWithNothingPendingIsRefused(): void
    {
        $this->pendingIdentities->forget();

        $this->expectException(PendingIdentityNotFoundException::class);

        $this->buildService()->getPendingEmail();
    }

    public function testCancelForgetsThePendingIdentity(): void
    {
        $customer = $this->customerWithPassword();
        $this->pendingIdentities->put($this->pendingIdentityFor($customer));

        $this->buildService()->cancel();

        $this->expectException(PendingIdentityNotFoundException::class);

        $this->pendingIdentities->peek();
    }

    public function testAWrongPasswordIsRefused(): void
    {
        $customer = $this->customerWithPassword();
        $this->pendingIdentities->put($this->pendingIdentityFor($customer));

        $this->expectException(InvalidPasswordException::class);

        $this->buildService()->attach('not-the-password');
    }

    public function testARightPasswordLinksAndSignsIn(): void
    {
        $customer = $this->customerWithPassword();
        $this->pendingIdentities->put($this->pendingIdentityFor($customer));

        $outcome = $this->buildService()->attach('password');

        self::assertSame(SocialLoginOutcomeStatus::LoggedIn, $outcome->status);
        self::assertSame($customer->getId(), $outcome->customer?->getId());
        self::assertCount(1, $this->identities->findForCustomer($customer->getId()));

        // The pending identity is single-use: a second peek finds nothing to attach.
        $this->expectException(PendingIdentityNotFoundException::class);
        $this->pendingIdentities->peek();
    }

    /**
     * The limiter's consume() runs before checkPassword(): a right guess must not be
     * free just because it happens to be the one that would have succeeded. Five wrong
     * guesses exhaust PasswordAttemptLimiter's budget (MAXIMUM_ATTEMPTS = 5); the sixth
     * call is refused as TooManyAttemptsException before the password — the right one,
     * this time — is even looked at.
     */
    public function testTheAttemptLimiterIsConsumedBeforeThePasswordIsChecked(): void
    {
        $customer = $this->customerWithPassword();
        $service = $this->buildService();

        for ($i = 0; $i < 5; ++$i) {
            $this->pendingIdentities->put($this->pendingIdentityFor($customer));

            try {
                $service->attach('wrong-password-'.$i);
            } catch (InvalidPasswordException) {
                // Expected: the budget is spent regardless of the guess being wrong.
            }
        }

        $this->pendingIdentities->put($this->pendingIdentityFor($customer));

        $this->expectException(TooManyAttemptsException::class);

        $service->attach('password');
    }

    /**
     * Between the peek that names the account and the link() call, another request may
     * have attached the very same (provider, provider_identifier) pair to a different
     * account — a fresh sign-in racing the attachment, for instance. link() then answers
     * with the row that won, and attach() refuses to sign this caller into somebody
     * else's account on the strength of it.
     */
    public function testAnIdentityLinkedToAnotherAccountByTheTimeItIsAttachedIsRefused(): void
    {
        $customer = $this->customerWithPassword();
        $identity = $this->pendingIdentityFor($customer);
        $this->pendingIdentities->put($identity);

        $winner = $this->customerWithPassword();
        $this->identities->link($winner, $identity);

        try {
            $this->buildService()->attach('password');
            self::fail('Expected an IdentityAlreadyLinkedException to be thrown.');
        } catch (IdentityAlreadyLinkedException) {
            // Expected.
        }

        // The pending identity is discarded either way: the visitor starts over rather
        // than being told which race they lost.
        $this->expectException(PendingIdentityNotFoundException::class);
        $this->pendingIdentities->peek();
    }

    private function customerWithPassword(): Customer
    {
        $factory = $this->createFixtureFactory();

        return $factory->customer($factory->customerTitle());
    }

    private function pendingIdentityFor(Customer $customer): VerifiedIdentity
    {
        return new VerifiedIdentity(
            provider: 'google',
            identifier: 'sub-'.uniqid('', true),
            email: $customer->getEmail(),
            emailVerified: true,
        );
    }

    private function buildService(): IdentityAttachmentService
    {
        return new IdentityAttachmentService(
            $this->pendingIdentities,
            $this->identities,
            new PasswordAttemptLimiter(
                new ArrayAdapter(),
                $this->getService(RequestStack::class),
                new LockFactory(new InMemoryStore()),
            ),
            $this->getService(CustomerAuthenticator::class),
            new NullLogger(),
        );
    }
}
