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
use SocialLogin\DTO\VerifiedIdentity;
use SocialLogin\Exception\InvalidPasswordException;
use SocialLogin\Exception\PasswordConfirmationRequiredException;
use SocialLogin\Service\IdentityDetachmentService;
use SocialLogin\Service\PasswordAttemptLimiter;
use SocialLogin\Service\SocialLoginIdentityRepository;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Thelia\Model\Customer;
use Thelia\Test\IntegrationTestCase;

/**
 * Detaching the last identity of an account takes the providers away as a way in, so it
 * is refused unless the account's own password is proved — the fixture customer always
 * carries the FixtureFactory default password, 'password'.
 */
final class IdentityDetachmentServiceTest extends IntegrationTestCase
{
    private SocialLoginIdentityRepository $identities;

    protected function setUp(): void
    {
        parent::setUp();
        $this->identities = new SocialLoginIdentityRepository();
    }

    public function testDetachingTheLastIdentityWithoutAPasswordIsRefused(): void
    {
        $customer = $this->customerWithOneIdentity();

        $this->expectException(PasswordConfirmationRequiredException::class);

        $this->buildService()->detach($customer, $this->onlyIdentityIdOf($customer));
    }

    public function testDetachingTheLastIdentityWithTheWrongPasswordIsRefused(): void
    {
        $customer = $this->customerWithOneIdentity();
        $identityId = $this->onlyIdentityIdOf($customer);

        $this->expectException(InvalidPasswordException::class);

        $this->buildService()->detach($customer, $identityId, 'not-the-password');
    }

    public function testDetachingTheLastIdentityWithTheRightPasswordRemovesIt(): void
    {
        $customer = $this->customerWithOneIdentity();
        $identityId = $this->onlyIdentityIdOf($customer);

        $this->buildService()->detach($customer, $identityId, 'password');

        self::assertCount(0, $this->identities->findForCustomer($customer->getId()));
    }

    private function customerWithOneIdentity(): Customer
    {
        $factory = $this->createFixtureFactory();
        $customer = $factory->customer($factory->customerTitle());
        $this->identities->link($customer, new VerifiedIdentity('google', 'sub-'.uniqid('', true), 'x@test.com', true));

        return $customer;
    }

    private function onlyIdentityIdOf(Customer $customer): int
    {
        $identities = $this->identities->findForCustomer($customer->getId());
        self::assertCount(1, $identities);

        return (int) $identities[0]->getId();
    }

    private function buildService(): IdentityDetachmentService
    {
        return new IdentityDetachmentService(
            new SocialLoginIdentityRepository(),
            new PasswordAttemptLimiter(
                new ArrayAdapter(),
                $this->getService(RequestStack::class),
                new LockFactory(new InMemoryStore()),
            ),
            new NullLogger(),
        );
    }
}
