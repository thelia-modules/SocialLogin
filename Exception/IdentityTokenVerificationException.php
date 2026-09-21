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
 * An identity token did not verify: signature, issuer, audience or expiry. Nothing the
 * token claimed may be used after this, whatever else the response carried.
 */
final class IdentityTokenVerificationException extends SocialLoginException
{
    public const string TRANSLATION_KEY = 'sociallogin.error.identity_token_verification';

    public function __construct(public readonly string $providerCode, public readonly string $reason, ?\Throwable $previous = null)
    {
        parent::__construct(self::TRANSLATION_KEY, 0, $previous);
    }
}
