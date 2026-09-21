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

namespace SocialLogin\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\EventDispatcher\Event;
use Thelia\Core\Event\TheliaEvents;

/**
 * Keeps the "confirm your address" code out of a social sign-up.
 *
 * On a shop that confirms addresses, registering mails an activation code, because a
 * fresh registration is nothing but a typed address until its owner reads it. A social
 * sign-up is not that case: the account is only ever opened when the provider says it
 * checked the mailbox, so {@see \SocialLogin\Service\SocialLoginService} enables the
 * account and clears the token right after creating it. The mail would then carry a code
 * that is already void — an invitation to act on, for an account that needs nothing.
 *
 * The core listener sits at priority 128 on this event and this one runs ahead of it,
 * but only while a social registration is actually in flight: the flag is raised around
 * the one call and lowered in a `finally`, so no other registration in the same request
 * loses its mail.
 *
 * Not `final readonly`, unlike everything else here: it is the state.
 */
final class AccountConfirmationEmailSuppressor implements EventSubscriberInterface
{
    private bool $suppressing = false;

    public function suppress(): void
    {
        $this->suppressing = true;
    }

    public function release(): void
    {
        $this->suppressing = false;
    }

    public function onSendAccountConfirmationEmail(Event $event): void
    {
        if ($this->suppressing) {
            $event->stopPropagation();
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TheliaEvents::SEND_ACCOUNT_CONFIRMATION_EMAIL => ['onSendAccountConfirmationEmail', 256],
        ];
    }
}
