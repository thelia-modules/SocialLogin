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
 * Detaching the last identity of an account takes its password away as a way in, so the
 * password is asked for first: an account whose owner does not know it would be left
 * with no way back in at all.
 */
final class PasswordConfirmationRequiredException extends SocialLoginException
{
    public const string TRANSLATION_KEY = 'sociallogin.error.password_confirmation_required';

    public function __construct()
    {
        parent::__construct(self::TRANSLATION_KEY);
    }
}
