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

namespace SocialLogin\Service;

use Propel\Runtime\Propel;
use Psr\Log\LoggerInterface;
use SocialLogin\Exception\IdentityNotOwnedException;
use SocialLogin\Exception\InvalidPasswordException;
use SocialLogin\Exception\PasswordConfirmationRequiredException;
use SocialLogin\Exception\TooManyAttemptsException;
use SocialLogin\Model\Map\SocialLoginIdentityTableMap;
use SocialLogin\Model\SocialLoginIdentity;
use Thelia\Model\Customer;

/**
 * Unlinks one provider from the signed-in account.
 *
 * Two things are refused. Unlinking somebody else's identity: the row is read by id from
 * a page, and a page is where an id gets edited, so ownership is checked against the
 * account doing the asking rather than assumed from the list it was picked in.
 *
 * And unlinking the last one without proving the password. An account opened through a
 * provider was given a password nobody holds, so for many of these accounts the
 * providers are the only way in; taking the last one away silently would lock its owner
 * out, and doing it from a stolen session would lock the owner out on purpose. Asking
 * for the password answers both at once — it is the proof that the person asking is the
 * owner, *and* the proof that a way in other than the providers exists.
 */
final readonly class IdentityDetachmentService
{
    public const string RATE_LIMIT_FLOW = 'detach';

    public function __construct(
        private SocialLoginIdentityRepository $identities,
        private PasswordAttemptLimiter $attemptLimiter,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<SocialLoginIdentity>
     */
    public function listFor(Customer $customer): array
    {
        return $this->identities->findForCustomer($customer->getId());
    }

    /**
     * Whether the page must ask for the password before offering the button.
     */
    public function requiresPasswordConfirmation(Customer $customer): bool
    {
        return 1 >= $this->identities->countForCustomer($customer->getId());
    }

    /**
     * The guard is a counted one, so it is only worth what the count is worth.
     *
     * Read outside a transaction, "is this the last one?" is answered about a set two
     * requests can be emptying at once: both see two identities, neither is asked for a
     * password, and the account ends with none — exactly the lock-out the prompt exists to
     * prevent. So the whole sequence runs inside one transaction, over rows read with an
     * exclusive lock, and the count is checked again *after* the delete: an account that
     * has just fallen to zero identities without its password ever being proved is rolled
     * back, not committed and apologised for.
     *
     * @param string|null $plainPassword required only when this is the account's last identity
     *
     * @throws IdentityNotOwnedException
     * @throws PasswordConfirmationRequiredException
     * @throws TooManyAttemptsException
     * @throws InvalidPasswordException
     */
    public function detach(Customer $customer, int $identityId, ?string $plainPassword = null): void
    {
        $customerId = $customer->getId();
        $detachedProvider = null;

        $connection = Propel::getWriteConnection(SocialLoginIdentityTableMap::DATABASE_NAME);
        $connection->beginTransaction();

        try {
            // The whole set first, and locked: every decision below is about how many rows
            // this account has, so they are all taken on a set no other transaction can
            // change underneath them.
            $owned = $this->identities->lockForCustomer($customerId, $connection);
            $identity = $this->identities->findOwnedBy($customerId, $identityId, $connection);

            // Same answer for "no such row" and "not yours": telling them apart says whether
            // a given id exists.
            if (!$identity instanceof SocialLoginIdentity) {
                throw new IdentityNotOwnedException();
            }

            $passwordProved = 1 === \count($owned) && $this->proveOwnership($customer, $plainPassword);

            $this->identities->remove($identity, $connection);

            // The count that actually decides. The one above only says whether to ask for
            // the password; this one says whether the account still has a way in, and it is
            // read after the row is gone, inside the transaction that took it away.
            if (0 === $this->identities->countForCustomer($customerId, $connection) && !$passwordProved) {
                throw new PasswordConfirmationRequiredException();
            }

            $detachedProvider = $identity->getProvider();

            $connection->commit();
        } catch (\Throwable $throwable) {
            $connection->rollBack();

            throw $throwable;
        }

        $this->logger->info('Social login: identity detached.', [
            'provider' => $detachedProvider,
            'customer_id' => $customerId,
        ]);
    }

    /**
     * @throws PasswordConfirmationRequiredException
     * @throws TooManyAttemptsException
     * @throws InvalidPasswordException
     */
    private function proveOwnership(Customer $customer, ?string $plainPassword): bool
    {
        if (null === $plainPassword || '' === $plainPassword) {
            throw new PasswordConfirmationRequiredException();
        }

        // Before the check, not after: a right guess must cost what a wrong one costs.
        $this->attemptLimiter->consume(self::RATE_LIMIT_FLOW, $customer->getId());

        if (!$customer->checkPassword($plainPassword)) {
            $this->logger->notice('Social login: wrong password on last identity detachment.', [
                'customer_id' => $customer->getId(),
            ]);

            throw new InvalidPasswordException();
        }

        return true;
    }
}
