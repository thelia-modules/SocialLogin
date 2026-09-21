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

final class TooManyAttemptsException extends SocialLoginException
{
    public const string TRANSLATION_KEY = 'sociallogin.error.too_many_attempts';

    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct(self::TRANSLATION_KEY);
    }
}
