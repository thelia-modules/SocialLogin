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
 * There is no identity waiting to be attached: none was ever put aside, it expired, it
 * was already used, or this browser has no session to hold it.
 */
final class PendingIdentityNotFoundException extends SocialLoginException
{
    public const string TRANSLATION_KEY = 'sociallogin.error.pending_identity_not_found';

    public function __construct()
    {
        parent::__construct(self::TRANSLATION_KEY);
    }
}
