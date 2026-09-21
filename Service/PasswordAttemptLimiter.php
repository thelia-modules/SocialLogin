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

use SocialLogin\Exception\TooManyAttemptsException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Caps how often one visitor may guess one account's password on this module's two
 * password prompts — attaching an identity, and detaching the last one.
 *
 * Both prompts take a password for an account the caller has named but not proved they
 * own, which is a password oracle unless something stops the repetition.
 *
 * Why a counter in the cache rather than `symfony/rate-limiter`: a limiter is declared
 * through the `framework.rate_limiter` configuration, whose factories are built by the
 * framework bundle's extension. A Thelia module configures services, not the framework
 * bundle, so it cannot add one, and reusing a core limiter meant for something else
 * would let one flow exhaust another's budget. What is here is a sliding window per
 * (flow, account, client address): the timestamps of the attempts inside the window are
 * kept, each `consume()` drops the ones that have aged out, and the allowance recovers
 * one guess at a time as the oldest attempts fall away — so a burst that straddles two
 * fixed windows can no longer buy a caller close to twice the budget.
 *
 * The address is part of the key, not all of it. On its own it would let one attacker
 * behind one address lock every account out; the account on its own would let a botnet
 * spread the guesses. Neither key alone is enough, so both are in it.
 *
 * Read-then-write is not atomic on any cache backend, and a limiter whose increment can
 * be lost is no limiter at all: a burst of parallel guesses all read the same count and
 * all write back the same one, so the budget is spent as many times over as there are
 * requests in flight. The counter is therefore read and written under a lock keyed on the
 * same triple, which serialises the requests that could tread on each other and leaves
 * the ones that could not alone.
 */
final readonly class PasswordAttemptLimiter
{
    private const int WINDOW_SECONDS = 900;
    private const int MAXIMUM_ATTEMPTS = 5;

    /**
     * Long enough for a cache round trip and no longer: the lock guards two cache calls,
     * and a holder that dies mid-flight must not keep an account's prompt shut.
     */
    private const int LOCK_TIMEOUT_SECONDS = 5;

    public function __construct(
        #[Autowire(service: 'thelia.cache.security')]
        private CacheInterface $cache,
        private RequestStack $requestStack,
        private LockFactory $lockFactory,
    ) {
    }

    /**
     * Records one attempt and refuses once the sliding window is full.
     *
     * Called before the password is checked, so that a wrong guess and a right one cost
     * the same and the counter cannot be walked around by guessing correctly.
     *
     * @throws TooManyAttemptsException
     */
    public function consume(string $flow, int $customerId): void
    {
        $clientAddress = $this->requestStack->getMainRequest()?->getClientIp() ?? 'cli';
        $key = 'sociallogin.attempts.'.hash('sha256', $flow.'|'.$customerId.'|'.$clientAddress);

        $lock = $this->lockFactory->createLock($key, self::LOCK_TIMEOUT_SECONDS);
        $lock->acquire(true);

        try {
            $this->record($key);
        } finally {
            $lock->release();
        }
    }

    /**
     * The sliding window itself, run with the lock held.
     *
     * The attempt timestamps within the window are read back, the ones that have aged out
     * are pruned, and the call is refused when what is left already fills the allowance.
     * Only an accepted attempt is recorded, so a caller already over the limit cannot push
     * the window forward by hammering it.
     *
     * @throws TooManyAttemptsException
     */
    private function record(string $key): void
    {
        $now = time();

        // Stored as JSON rather than as an array: a cache entry outlives a deployment, so
        // what comes back is read and checked rather than taken at its word.
        $stored = $this->cache->get($key, static fn (ItemInterface $item): string => self::encode($item, []));

        $decoded = json_decode($stored, true);

        $attempts = [];

        // A cache entry outlives a deployment, and an older one held a {startedAt, count}
        // object whose values happen to be numbers: mining those for timestamps would hand
        // a blocked caller a fresh allowance the moment a deployment lands. Only a JSON
        // list of integers is a window; anything else is treated as empty and starts over.
        if (\is_array($decoded) && array_is_list($decoded)) {
            foreach ($decoded as $timestamp) {
                if (!\is_int($timestamp)) {
                    continue;
                }

                if ($timestamp <= $now && ($now - $timestamp) < self::WINDOW_SECONDS) {
                    $attempts[] = $timestamp;
                }
            }
        }

        $refused = \count($attempts) >= self::MAXIMUM_ATTEMPTS;

        if (!$refused) {
            $attempts[] = $now;
        }

        // The contract has no write, so the entry is replaced: deleted, then recomputed
        // with the pruned window. Its lifetime is the full window, since the freshest
        // attempt it holds cannot matter for longer than that.
        $this->cache->delete($key);
        $this->cache->get($key, static fn (ItemInterface $item): string => self::encode($item, $attempts));

        if ($refused) {
            // Recoverable one attempt at a time: the budget frees a slot when its oldest
            // attempt leaves the window, so that is when a caller may try again. The delay
            // is carried on the exception for a future Retry-After header or user message;
            // it is computed here rather than at the point of use so the window is its
            // single source of truth. Not yet surfaced by the UI.
            throw new TooManyAttemptsException(max(1, self::WINDOW_SECONDS - ($now - min($attempts))));
        }
    }

    /**
     * @param list<int> $attempts
     */
    private static function encode(ItemInterface $item, array $attempts): string
    {
        $item->expiresAfter(self::WINDOW_SECONDS);

        return (string) json_encode($attempts);
    }
}
