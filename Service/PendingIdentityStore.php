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

use SocialLogin\DTO\VerifiedIdentity;
use SocialLogin\Exception\PendingIdentityNotFoundException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Holds a verified identity between the callback that produced it and the page where its
 * owner proves the shop account is theirs.
 *
 * The identity waits in the session, but it is not *trusted* because it is in the
 * session: what is stored is a signed, timestamped payload, so a value planted in a
 * fixated session buys nothing and one left behind by an abandoned attempt stops being
 * usable ten minutes later, whatever the session's own lifetime.
 *
 * There is no fallback when there is no session — on the command line, or on a browser
 * that keeps no cookies. Attaching an identity is a two-page conversation with one
 * person, and there is nowhere else to put half of it; the caller gets an explicit
 * refusal rather than a fatal error.
 */
final readonly class PendingIdentityStore
{
    private const string SESSION_KEY = 'sociallogin.pending_identity';
    private const string PURPOSE = 'pending_identity';
    private const int LIFETIME_SECONDS = 600;

    public function __construct(
        private RequestStack $requestStack,
        private SignedPayloadCodec $codec,
    ) {
    }

    /**
     * @throws PendingIdentityNotFoundException when this request has no session to hold it
     */
    public function put(VerifiedIdentity $identity): void
    {
        $session = $this->session();

        if (null === $session) {
            throw new PendingIdentityNotFoundException();
        }

        $session->set(self::SESSION_KEY, $this->codec->encode(self::PURPOSE, $identity->toArray()));
    }

    /**
     * Read it without spending it: a wrong password must not cost the visitor the whole
     * trip back to the provider.
     *
     * @throws PendingIdentityNotFoundException
     */
    public function peek(): VerifiedIdentity
    {
        $session = $this->session();

        if (null === $session) {
            throw new PendingIdentityNotFoundException();
        }

        $token = $session->get(self::SESSION_KEY);

        if (!\is_string($token) || '' === $token) {
            throw new PendingIdentityNotFoundException();
        }

        $payload = $this->codec->decode(self::PURPOSE, $token, self::LIFETIME_SECONDS);

        if (null === $payload) {
            $this->forget();

            throw new PendingIdentityNotFoundException();
        }

        try {
            return VerifiedIdentity::fromArray($payload);
        } catch (\InvalidArgumentException) {
            $this->forget();

            throw new PendingIdentityNotFoundException();
        }
    }

    public function forget(): void
    {
        $this->session()?->remove(self::SESSION_KEY);
    }

    /**
     * The current request's session, or null when there is none to hold a pending
     * identity — no request at all, on the command line, or a request whose session was
     * never started. Every method above goes through this rather than its own
     * `hasSession()` guard, so the "no session" case is decided in one place.
     */
    private function session(): ?SessionInterface
    {
        $request = $this->requestStack->getMainRequest();

        if (null === $request || !$request->hasSession()) {
            return null;
        }

        return $request->getSession();
    }
}
