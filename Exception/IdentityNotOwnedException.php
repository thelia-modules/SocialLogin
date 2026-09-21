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
 * The identity asked about belongs to another account, or to none. Same answer for both:
 * a different one would say whether a given identity row exists.
 */
final class IdentityNotOwnedException extends SocialLoginException
{
    public const string TRANSLATION_KEY = 'sociallogin.error.identity_not_owned';

    public function __construct()
    {
        parent::__construct(self::TRANSLATION_KEY);
    }
}
