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
use SocialLogin\DTO\StateChallenge;
use SocialLogin\Exception\InvalidStateException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Lock\LockFactory;

/**
 * Ties a return from a provider to a departure this very browser made.
 *
 * Without it, anyone can call the callback URL with an authorization code of their own
 * and have the shop sign the visitor into *their* account — the login-CSRF that `state`
 * exists to stop.
 *
 * The usual place to keep the expected value is the session, and here it cannot be.
 * Apple returns by `POST` from appleid.apple.com, and a cross-site POST carries no
 * `SameSite=Lax` cookie, so the session at the callback is not the session at the
 * departure — it may not exist at all. So the value is split in two:
 *
 * - the `state` parameter itself, which carries the provider, the mint time and the
 *   *hash* of a nonce, signed by {@see SignedPayloadCodec}. Signed, it cannot be written
 *   by anyone else; carrying only the hash, it does not hand the nonce to whoever sees
 *   the URL in a log or a referrer;
 * - a cookie holding the nonce, set `SameSite=None` so that it is sent on Apple's
 *   cross-site POST, `Secure` and `HttpOnly` so that it travels only over TLS and is out
 *   of reach of scripts.
 *
 * A return is accepted only when both halves are present and agree. The cookie is then
 * cleared by the caller, and the nonce is spent server side on the way through, which is
 * what makes the state single-use: the same `state` replayed afterwards has no nonce to
 * match, one replayed from another browser never had one, and one replayed *with* its
 * cookie — from a browser whose cookie jar was copied, or before the clearing response
 * ever arrived — finds the nonce already spent.
 *
 * Note for deployment: `SameSite=None` requires `Secure`, so the cookie is not stored by
 * a browser over plain HTTP. Sign-in must therefore be exercised over TLS.
 */
final readonly class StateManager
{
    public const string COOKIE_NAME = 'sociallogin_state';

    private const string PURPOSE = 'state';
    private const int LIFETIME_SECONDS = 600;

    /** Spent nonces are kept exactly as long as a state can be presented, and no longer. */
    private const string SPENT_KEY_PREFIX = 'sociallogin.state.spent.';

    /**
     * Long enough for a read and a write against the cache and no longer: a holder that
     * dies mid-flight must not keep a nonce from ever being spent.
     */
    private const int LOCK_TIMEOUT_SECONDS = 5;

    public function __construct(
        private SignedPayloadCodec $codec,
        #[Autowire(service: 'thelia.cache.security')]
        private CacheItemPoolInterface $spentNonces,
        private LockFactory $lockFactory,
    ) {
    }

    public function start(string $providerCode): StateChallenge
    {
        $nonce = bin2hex(random_bytes(32));

        $state = $this->codec->encode(self::PURPOSE, [
            'provider' => $providerCode,
            'nonce' => hash('sha256', $nonce),
        ]);

        return new StateChallenge($state, $this->cookie($nonce, time() + self::LIFETIME_SECONDS));
    }

    /**
     * @throws InvalidStateException
     */
    public function validate(string $providerCode, ?string $state, ?string $cookieValue): void
    {
        if (null === $state || '' === $state) {
            throw new InvalidStateException('missing_state');
        }

        if (null === $cookieValue || '' === $cookieValue) {
            throw new InvalidStateException('missing_cookie');
        }

        $payload = $this->codec->decode(self::PURPOSE, $state, self::LIFETIME_SECONDS);

        if (null === $payload) {
            throw new InvalidStateException('unsigned_or_expired');
        }

        if ($providerCode !== ($payload['provider'] ?? null)) {
            throw new InvalidStateException('provider_mismatch');
        }

        $expectedNonce = $payload['nonce'] ?? null;

        if (!\is_string($expectedNonce) || !hash_equals($expectedNonce, hash('sha256', $cookieValue))) {
            throw new InvalidStateException('nonce_mismatch');
        }

        $this->spend($expectedNonce);
    }

    /**
     * Single use kept on the server, where clearing a cookie cannot be declined.
     *
     * The cookie makes a replay from another browser impossible; it does nothing about a
     * replay from the same one, whose jar may have been copied or whose clearing response
     * may never have arrived. What is written is the hash the `state` already carries —
     * nothing that was not in it — and it is written for exactly as long as a `state` can
     * still be presented, after which the signature's own lifetime check takes over.
     *
     * Read-then-write against a cache is not atomic, so two strictly simultaneous replays
     * could both read the nonce as unspent and both be let through. The read and the mark
     * are therefore run under a blocking lock keyed on the same nonce: the second replay
     * waits, then finds the nonce already spent and is refused.
     *
     * @throws InvalidStateException
     */
    private function spend(string $nonceHash): void
    {
        $lock = $this->lockFactory->createLock('sociallogin-state-'.$nonceHash, self::LOCK_TIMEOUT_SECONDS);
        $lock->acquire(true);

        try {
            $item = $this->spentNonces->getItem(self::SPENT_KEY_PREFIX.$nonceHash);

            if ($item->isHit()) {
                throw new InvalidStateException('already_spent');
            }

            $item->set(true);
            $item->expiresAfter(self::LIFETIME_SECONDS);

            $this->spentNonces->save($item);
        } finally {
            $lock->release();
        }
    }

    /**
     * The cookie that spends the challenge. Set on the callback response, whatever the
     * outcome: a failed return must not leave a nonce behind to try again with.
     */
    public function clearCookie(): Cookie
    {
        return $this->cookie('', 1);
    }

    private function cookie(string $value, int $expiresAt): Cookie
    {
        return Cookie::create(
            name: self::COOKIE_NAME,
            value: $value,
            expire: $expiresAt,
            path: '/',
            secure: true,
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_NONE,
        );
    }
}
