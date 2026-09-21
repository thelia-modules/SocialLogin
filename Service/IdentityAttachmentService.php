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

use Psr\Log\LoggerInterface;
use SocialLogin\DTO\SocialLoginOutcome;
use SocialLogin\Exception\IdentityAlreadyLinkedException;
use SocialLogin\Exception\InvalidPasswordException;
use SocialLogin\Exception\PendingIdentityNotFoundException;
use SocialLogin\Exception\TooManyAttemptsException;
use Thelia\Domain\Customer\Service\CustomerAuthenticator;
use Thelia\Model\Customer;

/**
 * The second half of the one case social login refuses to decide on its own: an identity
 * nobody has linked yet, on an address that already has an account.
 *
 * The provider proved who the visitor is *there*. Nothing so far proves the shop account
 * on the same address is theirs — addresses are reused, mistyped and handed on — so the
 * account's own password is what proves it, checked the same way a sign-in checks it
 * ({@see Customer::checkPassword()}, which is what
 * {@see \Thelia\Core\Security\Authentication\UsernamePasswordFormAuthenticator} calls).
 *
 * That makes this a password prompt for an account the caller merely named, which is a
 * guessing oracle without {@see PasswordAttemptLimiter}.
 */
final readonly class IdentityAttachmentService
{
    public const string RATE_LIMIT_FLOW = 'attach';

    public function __construct(
        private PendingIdentityStore $pendingIdentities,
        private SocialLoginIdentityRepository $identities,
        private PasswordAttemptLimiter $attemptLimiter,
        private CustomerAuthenticator $customerAuthenticator,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The address waiting to be confirmed, for the page that asks for the password.
     *
     * @throws PendingIdentityNotFoundException
     */
    public function getPendingEmail(): string
    {
        return (string) $this->pendingIdentities->peek()->email;
    }

    /**
     * @throws PendingIdentityNotFoundException
     * @throws TooManyAttemptsException
     * @throws InvalidPasswordException
     * @throws IdentityAlreadyLinkedException
     * @throws \Thelia\Domain\Customer\Exception\CustomerNotEnabledException
     */
    public function attach(string $plainPassword): SocialLoginOutcome
    {
        $identity = $this->pendingIdentities->peek();

        if (null === $identity->email || !$identity->emailVerified) {
            $this->pendingIdentities->forget();

            throw new PendingIdentityNotFoundException();
        }

        // The very query {@see SocialLoginService} refused to open an account over, so that
        // the password proved here belongs to the account that stood in the way.
        $customer = $this->identities->findRegisteredAccountByEmail($identity->email);

        if (!$customer instanceof Customer) {
            // The account went away, or turned out to be the guest record the opening path
            // already refuses. Either way there is nothing to attach to, and the visitor
            // starts over rather than being told which it was.
            $this->pendingIdentities->forget();

            throw new PendingIdentityNotFoundException();
        }

        // Before the check, not after: a right guess must cost what a wrong one costs, or
        // the budget can be walked around by whoever finds the password.
        $this->attemptLimiter->consume(self::RATE_LIMIT_FLOW, $customer->getId());

        if (!$customer->checkPassword($plainPassword)) {
            $this->logger->notice('Social login: wrong password on identity attachment.', [
                'provider' => $identity->provider,
                'customer_id' => $customer->getId(),
            ]);

            throw new InvalidPasswordException();
        }

        $linked = $this->identities->link($customer, $identity);

        // link() answers with the row that won the unique index, which is not necessarily
        // the one just written: another account may have attached the same provider
        // identity in between. Signing this one in on somebody else's row would attach
        // nothing and say it worked, so the attempt is refused — the same reading
        // {@see SocialLoginService::openAccount()} makes of a lost race, decided the other
        // way because there is an account here already and it is not the winner.
        if ($linked->getCustomerId() !== $customer->getId()) {
            $this->pendingIdentities->forget();

            $this->logger->notice('Social login: identity already linked to another account, attachment refused.', [
                'provider' => $identity->provider,
                'customer_id' => $customer->getId(),
                'linked_customer_id' => $linked->getCustomerId(),
            ]);

            throw new IdentityAlreadyLinkedException();
        }

        $this->pendingIdentities->forget();

        $this->customerAuthenticator->processLogin($customer);

        $this->logger->info('Social login: identity attached to an existing account.', [
            'provider' => $identity->provider,
            'customer_id' => $customer->getId(),
        ]);

        return SocialLoginOutcome::loggedIn($customer);
    }

    /**
     * Give up on the identity set aside, for a visitor who changed their mind.
     */
    public function cancel(): void
    {
        $this->pendingIdentities->forget();
    }
}
