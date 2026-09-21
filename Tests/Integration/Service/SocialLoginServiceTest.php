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
use SocialLogin\EventListener\AccountConfirmationEmailSuppressor;
use SocialLogin\Exception\EmailNotProvidedException;
use SocialLogin\Exception\EmailNotVerifiedException;
use SocialLogin\Exception\OrphanedIdentityException;
use SocialLogin\Model\Map\SocialLoginIdentityTableMap;
use SocialLogin\Service\PendingIdentityStore;
use SocialLogin\Service\SignedPayloadCodec;
use SocialLogin\Service\SocialLoginIdentityRepository;
use SocialLogin\Service\SocialLoginService;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Customer\CustomerFacade;
use Thelia\Domain\Customer\Service\CustomerAuthenticator;
use Thelia\Model\ConfigQuery;
use Thelia\Model\CustomerQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * SocialLoginService::completeLogin() is where the three cases described on the class
 * itself are actually kept apart. Its own collaborators
 * (CustomerFacade, CustomerAuthenticator) are fetched from the container — they are
 * `Thelia\`-namespaced core services and public in the test container — while the
 * module's own services are plain, side-effect-free constructions: nothing here
 * declares them public, and there is no need to, since the interesting behaviour is
 * SocialLoginService's own.
 */
final class SocialLoginServiceTest extends IntegrationTestCase
{
    private SocialLoginIdentityRepository $identities;

    protected function setUp(): void
    {
        parent::setUp();
        $this->identities = new SocialLoginIdentityRepository();
    }

    public function testAKnownIdentitySignsInAndItsLastLoginIsUpdated(): void
    {
        $factory = $this->createFixtureFactory();
        $customer = $factory->customer($factory->customerTitle());
        $identity = $this->identities->link($customer, new VerifiedIdentity('google', 'sub-known', 'known@test.com', true));
        $identity->setLastLoginAt(new \DateTimeImmutable('2000-01-01'))->save();

        $outcome = $this->buildService()->completeLogin(new VerifiedIdentity('google', 'sub-known', 'known@test.com', true));

        self::assertSame(SocialLoginOutcomeStatus::LoggedIn, $outcome->status);
        self::assertSame($customer->getId(), $outcome->customer?->getId());
        self::assertTrue($this->getService(SecurityContext::class)->hasCustomerUser());

        $reloaded = $this->identities->findByProviderIdentity('google', 'sub-known');
        self::assertNotNull($reloaded);
        self::assertGreaterThan(
            (new \DateTimeImmutable('2000-01-01'))->getTimestamp(),
            $reloaded->getLastLoginAt('U'),
        );
    }

    /**
     * Email confirmation is turned on for the call: on a shop that leaves it off, every
     * fresh registration already comes back enabled, and the assertion below would pass
     * whether or not the module does anything about it. Only with confirmation on does a
     * plain registration land disabled — which is exactly the case
     * {@see SocialLoginService::enableOnVerifiedAddress()} exists
     * to override, since the provider already vouched for the address.
     */
    public function testAnUnknownIdentityOnAFreeAddressOpensAnEnabledAccount(): void
    {
        $outcome = $this->withEmailConfirmation(true, fn () => $this->buildService()->completeLogin(new VerifiedIdentity(
            'google',
            'sub-new-'.uniqid('', true),
            'brand-new-'.uniqid('', true).'@test.com',
            true,
            'Jane',
            'Doe',
        )));

        self::assertSame(SocialLoginOutcomeStatus::LoggedIn, $outcome->status);
        self::assertNotNull($outcome->customer);
        self::assertSame(1, $outcome->customer->getEnable());
        self::assertNull($outcome->customer->getConfirmationToken());

        // Not read off $outcome->customer: signing it in erases its in-memory password
        // on purpose (Customer::eraseCredentials(), called by
        // SecurityContext::setCustomerUser()) so that the credential does not linger
        // in the object the session then holds. What is asserted here is that a real,
        // non-empty hash was actually persisted when the account was opened.
        $persisted = CustomerQuery::create()->findPk($outcome->customer->getId());
        self::assertNotNull($persisted);
        self::assertNotEmpty($persisted->getPassword());

        $linked = $this->identities->findForCustomer($outcome->customer->getId());
        self::assertCount(1, $linked);
        self::assertSame('google', $linked[0]->getProvider());
    }

    public function testAnUnknownIdentityOnAnExistingAccountRequiresAttachmentWithoutSigningIn(): void
    {
        $factory = $this->createFixtureFactory();
        $customer = $factory->customer(
            $factory->customerTitle(),
            ['email' => 'has-a-password-'.uniqid('', true).'@test.com'],
        );

        $outcome = $this->buildService()->completeLogin(new VerifiedIdentity(
            'google',
            'sub-attach-'.uniqid('', true),
            $customer->getEmail(),
            true,
        ));

        self::assertSame(SocialLoginOutcomeStatus::AttachmentRequired, $outcome->status);
        self::assertNull($outcome->customer);
        self::assertFalse($this->getService(SecurityContext::class)->hasCustomerUser());
        self::assertCount(0, $this->identities->findForCustomer($customer->getId()));
    }

    /**
     * completeLogin() never gets as far as "open an account" once (provider,
     * provider_identifier) already resolves to a row: the pre-existing pair is what
     * decides, regardless of what email the return happens to carry.
     */
    public function testAPreExistingProviderIdentifierNeverOpensASecondAccount(): void
    {
        $factory = $this->createFixtureFactory();
        $customer = $factory->customer($factory->customerTitle());
        $this->identities->link($customer, new VerifiedIdentity('google', 'sub-dup', 'dup@test.com', true));
        $customersBefore = CustomerQuery::create()->count();

        $outcome = $this->buildService()->completeLogin(new VerifiedIdentity('google', 'sub-dup', 'dup@test.com', true));

        self::assertSame(SocialLoginOutcomeStatus::LoggedIn, $outcome->status);
        self::assertSame($customer->getId(), $outcome->customer?->getId());
        self::assertSame($customersBefore, CustomerQuery::create()->count());
    }

    /**
     * An address the provider will not name at all: there is nothing to open an
     * account on and nothing to match an existing one against.
     */
    public function testAnIdentityWithNoEmailIsRefused(): void
    {
        $this->expectException(EmailNotProvidedException::class);

        $this->buildService()->completeLogin(new VerifiedIdentity('google', 'sub-no-email-'.uniqid('', true), null, false));
    }

    /**
     * An address the provider names but does not vouch for: matching or opening on it
     * would let anyone who can type an address into that provider claim it here.
     */
    public function testAnIdentityWithAnUnverifiedEmailIsRefused(): void
    {
        $this->expectException(EmailNotVerifiedException::class);

        $this->buildService()->completeLogin(new VerifiedIdentity(
            'google',
            'sub-unverified-'.uniqid('', true),
            'unverified-'.uniqid('', true).'@test.com',
            false,
        ));
    }

    /**
     * The address belongs to a guest record only — the passwordless trace a guest order
     * leaves behind. The core opens that account on its mailed activation code alone, on
     * purpose, and social login does not override it: nobody is signed in, and the
     * visitor is told to check their mailbox instead.
     */
    public function testAnAddressThatOnlyHasAGuestRecordRequiresAccountActivation(): void
    {
        $factory = $this->createFixtureFactory();
        $guest = $factory->guestCustomer($factory->customerTitle());

        $outcome = $this->buildService()->completeLogin(new VerifiedIdentity(
            'google',
            'sub-guest-'.uniqid('', true),
            $guest->getEmail(),
            true,
        ));

        self::assertSame(SocialLoginOutcomeStatus::AccountActivationRequired, $outcome->status);
        self::assertNull($outcome->customer);
        self::assertFalse($this->getService(SecurityContext::class)->hasCustomerUser());
        self::assertCount(0, $this->identities->findForCustomer($guest->getId()));
    }

    /**
     * A row whose account is gone: the foreign key cascades, so this cannot happen
     * through the shop and only stands for data changed underneath it. Nothing is
     * signed in, and the refusal names what actually happened rather than blaming the
     * provider — the exchange with it worked perfectly.
     */
    public function testAnIdentityWhoseAccountIsGoneIsRefused(): void
    {
        $factory = $this->createFixtureFactory();
        $customer = $factory->customer($factory->customerTitle());
        $identifier = 'sub-orphan-'.uniqid('', true);
        $identity = $this->identities->link($customer, new VerifiedIdentity('google', $identifier, 'orphan@test.com', true));

        // The unique index the module relies on (provider, provider_identifier) does not
        // forbid this on its own: what makes the row orphaned is its customer_id no
        // longer naming an existing row, which the foreign key itself would refuse to
        // write under normal operation. Simulating data changed underneath the shop
        // needs the constraint held off for the one statement that breaks it.
        $connection = $this->getPropelConnection();
        $connection->exec('SET FOREIGN_KEY_CHECKS=0');

        try {
            $connection->exec(\sprintf(
                'UPDATE %s SET customer_id = 999999999 WHERE id = %d',
                SocialLoginIdentityTableMap::TABLE_NAME,
                (int) $identity->getId(),
            ));
        } finally {
            $connection->exec('SET FOREIGN_KEY_CHECKS=1');
        }

        $this->expectException(OrphanedIdentityException::class);

        $this->buildService()->completeLogin(new VerifiedIdentity('google', $identifier, 'orphan@test.com', true));
    }

    private function buildService(): SocialLoginService
    {
        return new SocialLoginService(
            new SocialLoginIdentityRepository(),
            $this->pendingIdentityStoreUnused(),
            $this->getService(CustomerFacade::class),
            $this->getService(CustomerAuthenticator::class),
            new AccountConfirmationEmailSuppressor(),
            $this->getService(RequestStack::class),
            new NullLogger(),
        );
    }

    /**
     * completeLogin() reaches PendingIdentityStore::put() only on the attachment path,
     * to set the identity aside for the password prompt. This suite never reads it back
     * — that is IdentityAttachmentService's job, exercised on its own — so a plainly
     * working store is enough for completeLogin() itself not to fail on the write.
     */
    private function pendingIdentityStoreUnused(): PendingIdentityStore
    {
        return new PendingIdentityStore(
            $this->getService(RequestStack::class),
            new SignedPayloadCodec('social-login-service-test-secret'),
        );
    }

    private function withEmailConfirmation(bool $enabled, callable $call): mixed
    {
        $wasEnabled = ConfigQuery::isCustomerEmailConfirmationEnable();
        ConfigQuery::write('customer_email_confirmation', $enabled ? '1' : '0');

        try {
            return $call();
        } finally {
            ConfigQuery::write('customer_email_confirmation', $wasEnabled ? '1' : '0');
        }
    }
}
