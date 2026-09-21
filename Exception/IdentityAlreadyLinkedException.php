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
 * The identity being attached turned out to belong to another account already.
 *
 * The unique index on (provider, provider_identifier) is what decides a race between two
 * attachments, and the loser reads back a row that is not its own. Claiming the
 * attachment succeeded would tell its owner they are linked to an account they are not
 * linked to, so the attempt is refused instead.
 */
final class IdentityAlreadyLinkedException extends SocialLoginException
{
    public const string TRANSLATION_KEY = 'sociallogin.error.identity_already_linked';

    public function __construct()
    {
        parent::__construct(self::TRANSLATION_KEY);
    }
}
