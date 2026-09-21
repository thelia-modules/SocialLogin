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
use SocialLogin\DTO\SocialLoginOutcome;
use SocialLogin\DTO\VerifiedIdentity;
use SocialLogin\EventListener\AccountConfirmationEmailSuppressor;
use SocialLogin\Exception\EmailNotProvidedException;
use SocialLogin\Exception\EmailNotVerifiedException;
use SocialLogin\Exception\OrphanedIdentityException;
use SocialLogin\Model\Map\SocialLoginIdentityTableMap;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Domain\Customer\CustomerFacade;
use Thelia\Domain\Customer\DTO\CustomerRegisterDTO;
use Thelia\Domain\Customer\Service\CustomerAuthenticator;
use Thelia\Model\Customer;
use Thelia\Model\Lang;

/**
 * What the shop does with an identity a provider has just vouched for.
 *
 * Three cases, and the whole security of the feature is in keeping them apart:
 *
 * 1. the pair (provider, identifier) is already linked to an account — that account, and
 *    no other, is signed in;
 * 2. it is not linked, and the address belongs to an account already — nobody is signed
 *    in. Matching on the address here is what turns "I can make a provider return an
 *    address" into "I own that shop account", so instead the identity is set aside and
 *    its owner is asked for the account's password ({@see IdentityAttachmentService});
 * 3. it is not linked and the address is free — an account is opened for it.
 *
 * An address that the provider does not vouch for never reaches any of the three: there
 * is no account to open on it, and no reason to believe anything it would be matched
 * against.
 */
final readonly class SocialLoginService
{
    public function __construct(
        private SocialLoginIdentityRepository $identities,
        private PendingIdentityStore $pendingIdentities,
        private CustomerFacade $customerFacade,
        private CustomerAuthenticator $customerAuthenticator,
        private AccountConfirmationEmailSuppressor $confirmationEmailSuppressor,
        private RequestStack $requestStack,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws EmailNotProvidedException
     * @throws EmailNotVerifiedException
     * @throws OrphanedIdentityException
     * @throws \Thelia\Domain\Customer\Exception\CustomerNotEnabledException
     */
    public function completeLogin(VerifiedIdentity $identity): SocialLoginOutcome
    {
        $email = $this->assertUsableEmail($identity);

        $known = $this->identities->findByProviderIdentity($identity->provider, $identity->identifier);

        if (null !== $known) {
            // Read back rather than followed through the relation: the generated getter is
            // documented as always returning a Customer and does not.
            $customer = $this->identities->findCustomerOf($known);

            if (!$customer instanceof Customer) {
                // The foreign key cascades, so a row without its account means the data was
                // changed underneath the shop. Nothing is signed in on a broken row, and the
                // refusal says so: the provider answered perfectly, and telling its owner
                // otherwise sends them to try another provider for a fault no provider has.
                throw new OrphanedIdentityException($identity->provider);
            }

            $this->identities->recordLogin($known, $identity->email);
            $this->customerAuthenticator->processLogin($customer);

            $this->logger->info('Social login: existing identity signed in.', [
                'provider' => $identity->provider,
                'customer_id' => $customer->getId(),
            ]);

            return SocialLoginOutcome::loggedIn($customer);
        }

        $existingAccount = $this->accountHolding($email);

        if (null !== $existingAccount) {
            if ($existingAccount->isGuest()) {
                // The passwordless record a guest order hangs off carries the history of
                // everyone who ever ordered on that address, and the core opens it on its
                // mailed activation code alone — deliberately, because the address does
                // not say who the orders belong to. Social login does not override that.
                $this->logger->info('Social login: address belongs to a guest record, activation required.', [
                    'provider' => $identity->provider,
                ]);

                return SocialLoginOutcome::accountActivationRequired($email);
            }

            $this->pendingIdentities->put($identity);

            $this->logger->info('Social login: address already has an account, attachment required.', [
                'provider' => $identity->provider,
                'customer_id' => $existingAccount->getId(),
            ]);

            return SocialLoginOutcome::attachmentRequired($email);
        }

        $customer = $this->openAccount($identity, $email);

        $this->customerAuthenticator->processLogin($customer);

        $this->logger->info('Social login: account opened and signed in.', [
            'provider' => $identity->provider,
            'customer_id' => $customer->getId(),
        ]);

        return SocialLoginOutcome::loggedIn($customer);
    }

    /**
     * @throws EmailNotProvidedException
     * @throws EmailNotVerifiedException
     */
    private function assertUsableEmail(VerifiedIdentity $identity): string
    {
        if (null === $identity->email || '' === trim($identity->email)) {
            throw new EmailNotProvidedException($identity->provider);
        }

        if (!$identity->emailVerified) {
            throw new EmailNotVerifiedException($identity->provider);
        }

        // Normalised here, once, and this is the value everything downstream uses: the
        // account is looked up on it, the account is opened on it, and the identity row
        // keeps it. Two providers spelling the same mailbox differently must not end up
        // being two different mailboxes.
        return EmailNormalizer::normalize($identity->email);
    }

    /**
     * The account an address already has, guest record included: both block an opening,
     * they just lead somewhere different.
     *
     * A real account is looked for first. `customer.email` is indexed but not unique, so
     * an address can carry both a guest record left by an old order and an account opened
     * later, and sending its owner to the activation flow when they have an account to
     * confirm the password of would be a dead end.
     *
     * Both reads are the repository's, which is also where
     * {@see IdentityAttachmentService} takes the first one from: the account this refuses
     * to open over and the account that is then asked for its password have to be the
     * same row.
     */
    private function accountHolding(string $email): ?Customer
    {
        return $this->identities->findRegisteredAccountByEmail($email)
            ?? $this->identities->findAnyAccountByEmail($email);
    }

    /**
     * Opening the account and linking the identity are one step or neither.
     *
     * `customer.email` carries an index, not a unique one, so nothing at the database
     * level stops two simultaneous first sign-ins from opening two accounts on the same
     * address. What is unique is (provider, provider_identifier), so the link is what
     * decides the race: the loser's insert is refused, its whole transaction — the new
     * account included — is rolled back, and it reads back the account the winner opened.
     */
    private function openAccount(VerifiedIdentity $identity, string $email): Customer
    {
        $connection = Propel::getWriteConnection(SocialLoginIdentityTableMap::DATABASE_NAME);
        $connection->beginTransaction();

        try {
            $customer = $this->registerCustomer($identity, $email);

            $linked = $this->identities->link($customer, $identity, $connection);

            if ($linked->getCustomerId() !== $customer->getId()) {
                throw new \RuntimeException('The identity was linked to another account while this one was being opened.');
            }

            $connection->commit();

            return $customer;
        } catch (\Throwable $throwable) {
            $connection->rollBack();

            $winner = $this->identities->findByProviderIdentity($identity->provider, $identity->identifier);
            $customer = null === $winner ? null : $this->identities->findCustomerOf($winner);

            if (!$customer instanceof Customer) {
                throw $throwable;
            }

            $this->logger->info('Social login: concurrent first sign-in, keeping the account that won.', [
                'provider' => $identity->provider,
                'customer_id' => $customer->getId(),
            ]);

            return $customer;
        }
    }

    private function registerCustomer(VerifiedIdentity $identity, string $email): Customer
    {
        // The core refuses a new non-guest account with no password
        // ({@see Customer::setPassword()}), and rightly: a row with an empty hash is a row
        // anybody can sign into. There is no password to ask for in an OAuth return, so
        // one is generated that nobody — the visitor included — will ever hold. Signing in
        // stays the provider's job; the password page of the account is how its owner
        // gives themselves a second way in.
        $unknowablePassword = bin2hex(random_bytes(32));

        [$firstName, $lastName] = $this->nameFor($identity, $email);

        $this->confirmationEmailSuppressor->suppress();

        try {
            $customer = $this->customerFacade->register(new CustomerRegisterDTO(
                firstname: $firstName,
                lastname: $lastName,
                email: $email,
                password: $unknowablePassword,
                langId: $this->currentLanguageId(),
            ));
        } finally {
            $this->confirmationEmailSuppressor->release();
        }

        $this->enableOnVerifiedAddress($customer);

        return $customer;
    }

    /**
     * A shop that confirms addresses opens every new account disabled, because a typed
     * address proves nothing. Here the provider has already done the confirming — it is
     * the only reason this account is being opened at all — so the account is enabled and
     * the pending code voided. This runs only on the path that proved the address; an
     * unverified one never gets here.
     */
    private function enableOnVerifiedAddress(Customer $customer): void
    {
        if (1 === $customer->getEnable() && null === $customer->getConfirmationToken()) {
            return;
        }

        $customer
            ->setEnable(1)
            ->clearConfirmationToken();

        $customer->save();
    }

    /**
     * The core stores both names as non-null columns and asks for them as strings, so
     * something has to go in when a provider gives neither — Apple hands the name over on
     * the very first authorisation and never again, so a returning visitor who is new to
     * *this* shop arrives without one.
     *
     * What goes in is the local part of the visitor's own address: it is the only thing
     * about them that is actually known, it is recognisable to them and to the shop, and
     * the first order overwrites it from the address form anyway. A placeholder would put
     * the same word on every such account and tell nobody anything.
     *
     * Both are cut to what the columns hold. The provider is where a name is bounded in
     * the first place — Apple's arrives in a browser-posted field nobody signed — but this
     * is the last point before the write, and a name that reaches the database too long
     * either fails the insert or is silently truncated. Neither belongs at the end of a
     * sign-in, so the cut is made here too rather than assumed to have happened already.
     *
     * @return array{0: string, 1: string}
     */
    private function nameFor(VerifiedIdentity $identity, string $email): array
    {
        $fallback = $this->nameFromEmail($email);

        return [
            $this->bounded($identity->firstName ?? $fallback),
            $this->bounded($identity->lastName ?? $identity->firstName ?? $fallback),
        ];
    }

    private function bounded(string $name): string
    {
        return mb_substr($name, 0, VerifiedIdentity::NAME_MAXIMUM_LENGTH);
    }

    private function nameFromEmail(string $email): string
    {
        $localPart = strstr($email, '@', true);
        $readable = trim(preg_replace('/[._\-+]+/', ' ', false === $localPart ? $email : $localPart) ?? '');

        return '' === $readable ? $email : ucwords($readable);
    }

    /**
     * Null when nothing says otherwise: the core then leaves the column alone and the
     * account falls back to the shop's default language. Guarded for the command line,
     * where there is no request and no session to ask.
     */
    private function currentLanguageId(): ?int
    {
        $request = $this->requestStack->getMainRequest();

        if (null === $request || !$request->hasSession()) {
            return null;
        }

        $session = $request->getSession();

        if (!$session instanceof Session) {
            return null;
        }

        $lang = $session->getLang();

        return $lang instanceof Lang ? $lang->getId() : null;
    }
}
