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

use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Exception\PropelException;
use SocialLogin\DTO\VerifiedIdentity;
use SocialLogin\Model\SocialLoginIdentity;
use SocialLogin\Model\SocialLoginIdentityQuery;
use Thelia\Model\Customer;
use Thelia\Model\CustomerQuery;

/**
 * The `social_login_identity` rows, and the one rule that governs them: a shop account is
 * recognised by the pair (provider, provider_identifier) and by nothing else. Never by
 * the address — an address changes hands, is reused across providers, and is claimed by
 * whoever can type it, while the pair is what the provider itself says is stable.
 */
final readonly class SocialLoginIdentityRepository
{
    /** MySQL reports every integrity violation, unique index included, under SQLSTATE 23000. */
    private const string INTEGRITY_VIOLATION_SQL_STATE = '23000';

    /**
     * The account a stored identity points at, read back rather than followed through the
     * generated relation: the getter is documented as always returning a Customer and does
     * not when the row's account is gone. A null answer is a row without its account — a
     * cascade that did not, data changed underneath the shop — for the caller to refuse on.
     *
     * @throws PropelException
     */
    public function findCustomerOf(SocialLoginIdentity $identity): ?Customer
    {
        return CustomerQuery::create()->findPk($identity->getCustomerId());
    }

    /**
     * @throws PropelException
     */
    public function findByProviderIdentity(string $provider, string $identifier): ?SocialLoginIdentity
    {
        return SocialLoginIdentityQuery::create()
            ->filterByProvider($provider)
            ->filterByProviderIdentifier($identifier)
            ->findOne();
    }

    /**
     * The one row an account may act on, read by the pair (account, id) rather than by id
     * alone: the id comes off a page, and a page is where an id gets edited. A row that is
     * not this account's reads as absent, which is the same answer "no such row" gets —
     * telling them apart says whether a given id exists.
     *
     * @throws PropelException
     */
    public function findOwnedBy(int $customerId, int $identityId, ?ConnectionInterface $connection = null): ?SocialLoginIdentity
    {
        return SocialLoginIdentityQuery::create()
            ->filterById($identityId)
            ->filterByCustomerId($customerId)
            ->findOne($connection);
    }

    /**
     * @return list<SocialLoginIdentity>
     *
     * @throws PropelException
     */
    public function findForCustomer(int $customerId): array
    {
        return array_values(
            SocialLoginIdentityQuery::create()
                ->filterByCustomerId($customerId)
                ->orderById()
                ->find()
                ->getData(),
        );
    }

    /**
     * @throws PropelException
     */
    public function deleteForCustomer(int $customerId): void
    {
        SocialLoginIdentityQuery::create()
            ->filterByCustomerId($customerId)
            ->delete();
    }

    /**
     * The account's identities, read under an exclusive row lock, for a caller inside a
     * transaction that is about to decide something on how many there are.
     *
     * Without the lock the decision is made on a count another transaction is in the
     * middle of changing, and under MVCC that other change stays invisible until it
     * commits — two simultaneous detachments would each see one identity left and each
     * take it away. `SELECT ... FOR UPDATE` is what makes the second one wait and then
     * read what the first actually left behind.
     *
     * @return list<SocialLoginIdentity>
     *
     * @throws PropelException
     */
    public function lockForCustomer(int $customerId, ConnectionInterface $connection): array
    {
        return array_values(
            SocialLoginIdentityQuery::create()
                ->filterByCustomerId($customerId)
                ->orderById()
                ->lockForUpdate()
                ->find($connection)
                ->getData(),
        );
    }

    /**
     * @throws PropelException
     */
    public function countForCustomer(int $customerId, ?ConnectionInterface $connection = null): int
    {
        return SocialLoginIdentityQuery::create()
            ->filterByCustomerId($customerId)
            ->count($connection);
    }

    /**
     * The account an address already belongs to, guest records left aside.
     *
     * One query, two callers, because they must agree: {@see SocialLoginService} refuses
     * to open a second account when this returns something, and
     * {@see IdentityAttachmentService} asks that very account for its password. If the two
     * ever picked different rows, the password proved would not belong to the account the
     * opening was refused over.
     *
     * `customer.email` carries an index, not a unique one, so a row has to be picked:
     * the oldest, which is the one an address's history hangs off.
     *
     * @throws PropelException
     */
    public function findRegisteredAccountByEmail(string $email): ?Customer
    {
        return CustomerQuery::create()
            ->filterByEmail(EmailNormalizer::normalize($email))
            ->filterByIsGuest(0)
            ->orderById()
            ->findOne();
    }

    /**
     * The same, guest records included — for the caller that has something to say about a
     * guest record rather than nothing. Kept apart from
     * {@see findRegisteredAccountByEmail()} on purpose: what a guest record means is the
     * caller's business, not this class's.
     *
     * @throws PropelException
     */
    public function findAnyAccountByEmail(string $email): ?Customer
    {
        return CustomerQuery::create()
            ->filterByEmail(EmailNormalizer::normalize($email))
            ->orderById()
            ->findOne();
    }

    /**
     * Link the identity to the account, or return the row that won the race.
     *
     * Two callbacks for the same identity can arrive at once — a double-clicked button is
     * enough — and both will find nothing and both will insert. The unique index is what
     * actually decides; the loser reads back what the winner wrote instead of leaving a
     * duplicate or a 500.
     *
     * @throws PropelException
     */
    public function link(Customer $customer, VerifiedIdentity $identity, ?ConnectionInterface $connection = null): SocialLoginIdentity
    {
        $row = (new SocialLoginIdentity())
            ->setCustomer($customer)
            ->setProvider($identity->provider)
            ->setProviderIdentifier($identity->identifier)
            ->setEmail(EmailNormalizer::normalizeOrNull($identity->email))
            ->setLastLoginAt(new \DateTimeImmutable());

        try {
            $row->save($connection);
        } catch (PropelException $exception) {
            if (!$this->isIntegrityViolation($exception)) {
                throw $exception;
            }

            $existing = $this->findByProviderIdentity($identity->provider, $identity->identifier);

            if (null === $existing) {
                throw $exception;
            }

            return $existing;
        }

        return $row;
    }

    /**
     * @throws PropelException
     */
    public function recordLogin(SocialLoginIdentity $identity, ?string $email): void
    {
        $identity->setLastLoginAt(new \DateTimeImmutable());

        // The address the provider holds today, kept for the back office to show; it is
        // never what the next sign-in is matched on.
        $normalized = EmailNormalizer::normalizeOrNull($email);

        if (null !== $normalized) {
            $identity->setEmail($normalized);
        }

        $identity->save();
    }

    /**
     * @throws PropelException
     */
    public function remove(SocialLoginIdentity $identity, ?ConnectionInterface $connection = null): void
    {
        $identity->delete($connection);
    }

    private function isIntegrityViolation(\Throwable $throwable): bool
    {
        for ($cause = $throwable; null !== $cause; $cause = $cause->getPrevious()) {
            if ($cause instanceof \PDOException && self::INTEGRITY_VIOLATION_SQL_STATE === (string) $cause->getCode()) {
                return true;
            }
        }

        return false;
    }
}
