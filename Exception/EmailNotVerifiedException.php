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
 * The provider returned an address it does not vouch for. Opening an account on it
 * would let anyone who can type an address into that provider claim it here.
 */
final class EmailNotVerifiedException extends SocialLoginException
{
    public const string TRANSLATION_KEY = 'sociallogin.error.email_not_verified';

    public function __construct(public readonly string $providerCode)
    {
        parent::__construct(self::TRANSLATION_KEY);
    }
}
