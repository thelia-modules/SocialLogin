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

use Psr\Cache\CacheItemPoolInterface;
use SocialLogin\DTO\VerifiedIdentity;
use SocialLogin\Exception\InvalidStateException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;

/**
 * Carries a verified identity from a cross-site POST callback to a same-site GET, without
 * touching the visitor's session in between.
 *
 * Apple returns by `POST` from appleid.apple.com. A cross-site POST carries no
 * `SameSite=Lax` cookie, so that request arrives with no session cookie at all — and
 * anything that then opens a session opens a *new* one, whose cookie replaces the
 * visitor's on the way back. The visitor loses whatever the old session held; on a shop
 * that means the cart, and the visitor was very possibly halfway through checkout when
 * they chose to sign in.
 *
 * So the POST finishes nothing. It parks the identity here, under a key nobody can guess,
 * and answers `303` to a plain GET that carries the key. That GET is a top-level
 * same-site navigation: the session cookie is sent, the session is the visitor's own, and
 * the sign-in completes on it.
 *
 * What makes the key safe to put in a URL: 32 random bytes, a single use — the entry is
 * deleted as it is read — and a minute to live, which is a redirect, not a journey. An
 * unknown, spent or expired key is refused the same way an invalid `state` is: it is the
 * same failure, arriving one step later.
 *
 * The key alone is not enough to finish, though — and that is the point. The POST that
 * parks the identity answers `303` and, on that same response, sets a short-lived cookie
 * holding a binding secret; the entry keeps only that secret's hash. `finish` is a GET
 * that must present both: the key from the URL and the cookie the POST left in the
 * browser that made it. So the key on its own — a link forwarded to a third party, a URL
 * lifted from a log — reaches an entry it cannot open, because that browser never got the
 * cookie. Only the browser that made the POST can complete the sign-in on its own session.
 */
final readonly class CrossSiteCallbackHandoff
{
    private const string KEY_PREFIX = 'sociallogin.handoff.';
    private const int LIFETIME_SECONDS = 60;
    private const string KEY_FORMAT = '/^[0-9a-f]{64}$/';

    /**
     * Long enough for a read and a delete against the cache and no longer: a holder that
     * dies mid-flight must not keep a key from ever being consumed.
     */
    private const int LOCK_TIMEOUT_SECONDS = 5;

    public function __construct(
        #[Autowire(service: 'thelia.cache.security')]
        private CacheItemPoolInterface $cache,
        private LockFactory $lockFactory,
    ) {
    }

    /**
     * @return array{key: string, bindingSecret: string} the key to hand back in the
     *                                                   redirect, and the secret to set as
     *                                                   a cookie on that same response
     */
    public function put(VerifiedIdentity $identity): array
    {
        $key = bin2hex(random_bytes(32));
        $bindingSecret = bin2hex(random_bytes(32));

        $item = $this->cache->getItem(self::KEY_PREFIX.$key);
        $item->set(json_encode([
            'identity' => $identity->toArray(),
            // Only the hash is kept: the entry is enough to check a presented secret, never
            // enough to reconstruct it, so a cache dump does not hand out valid cookies.
            'bindingHash' => hash('sha256', $bindingSecret),
        ], \JSON_THROW_ON_ERROR));
        $item->expiresAfter(self::LIFETIME_SECONDS);

        $this->cache->save($item);

        return ['key' => $key, 'bindingSecret' => $bindingSecret];
    }

    /**
     * Read once and gone, whatever the entry turns out to hold: a key that reaches this
     * method is spent by the time it returns, so a URL left in history or a referrer
     * cannot be walked back into a sign-in.
     *
     * The binding secret from the cookie is checked against the stored hash before the
     * identity is handed back: a key with no cookie, or with the wrong one, is refused
     * before anything is signed into. The read and the delete run under a blocking lock
     * keyed on the key, so two simultaneous GETs cannot both read the entry as present —
     * the second waits, finds it gone, and is refused.
     *
     * @throws InvalidStateException
     */
    public function consume(string $key, ?string $bindingSecret): VerifiedIdentity
    {
        if (1 !== preg_match(self::KEY_FORMAT, $key)) {
            throw new InvalidStateException('handoff_malformed');
        }

        if (null === $bindingSecret || '' === $bindingSecret) {
            throw new InvalidStateException('handoff_unbound');
        }

        $lock = $this->lockFactory->createLock('sociallogin-handoff-'.$key, self::LOCK_TIMEOUT_SECONDS);
        $lock->acquire(true);

        try {
            $item = $this->cache->getItem(self::KEY_PREFIX.$key);

            if (!$item->isHit()) {
                throw new InvalidStateException('handoff_unknown');
            }

            $this->cache->deleteItem(self::KEY_PREFIX.$key);

            $stored = $item->get();

            if (!\is_string($stored)) {
                throw new InvalidStateException('handoff_unreadable');
            }

            $payload = json_decode($stored, true);

            if (!\is_array($payload)) {
                throw new InvalidStateException('handoff_unreadable');
            }

            $bindingHash = $payload['bindingHash'] ?? null;

            if (!\is_string($bindingHash) || !hash_equals($bindingHash, hash('sha256', $bindingSecret))) {
                throw new InvalidStateException('handoff_unbound');
            }

            $identity = $payload['identity'] ?? null;

            if (!\is_array($identity)) {
                throw new InvalidStateException('handoff_unreadable');
            }

            // Keys restated as strings: a JSON object key that reads as a number comes back
            // from json_decode() as an int, and the DTO indexes by name.
            $named = [];

            foreach ($identity as $name => $value) {
                $named[(string) $name] = $value;
            }

            try {
                return VerifiedIdentity::fromArray($named);
            } catch (\InvalidArgumentException) {
                throw new InvalidStateException('handoff_unreadable');
            }
        } finally {
            $lock->release();
        }
    }
}
