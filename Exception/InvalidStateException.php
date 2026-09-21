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
 * The return could not be tied to a departure this browser made: missing, tampered
 * with, expired, or replayed once its cookie was spent. One exception for all four on
 * purpose — telling them apart tells an attacker which half they got right.
 */
final class InvalidStateException extends SocialLoginException
{
    public const string TRANSLATION_KEY = 'sociallogin.error.invalid_state';

    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::TRANSLATION_KEY);
    }
}
