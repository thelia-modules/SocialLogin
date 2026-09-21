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
 * An identity row whose account is gone.
 *
 * The foreign key cascades, so this state cannot be reached through the shop: it means
 * the data was changed underneath it. Nothing is signed in on such a row, and the
 * refusal says what actually happened rather than blaming the provider — the exchange
 * with the provider worked perfectly.
 */
final class OrphanedIdentityException extends SocialLoginException
{
    public const string TRANSLATION_KEY = 'sociallogin.error.identity_orphaned';

    public function __construct(public readonly string $providerCode)
    {
        parent::__construct(self::TRANSLATION_KEY);
    }
}
