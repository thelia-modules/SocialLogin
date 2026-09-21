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
 * The provider returned no address. An account with no address cannot be ordered from,
 * cannot be recovered and cannot be written to, so there is nothing to open.
 */
final class EmailNotProvidedException extends SocialLoginException
{
    public const string TRANSLATION_KEY = 'sociallogin.error.email_not_provided';

    public function __construct(public readonly string $providerCode)
    {
        parent::__construct(self::TRANSLATION_KEY);
    }
}
