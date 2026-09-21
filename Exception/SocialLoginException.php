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

namespace SocialLogin\Exception;

/**
 * Base of everything this module refuses on.
 *
 * The message is a translation key of the `sociallogin` domain, never a sentence and
 * never anything taken from a provider response: whatever reaches a page here is shown
 * to somebody who just failed to sign in, and a raw provider message is both unreadable
 * and a way to leak what the exchange carried.
 */
abstract class SocialLoginException extends \RuntimeException
{
    public function getTranslationKey(): string
    {
        return $this->getMessage();
    }
}
