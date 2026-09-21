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
 * The exchange with the provider failed, or it answered something unusable. The cause
 * is kept as `previous` for the log; the message stays a translation key.
 */
final class ProviderCommunicationException extends SocialLoginException
{
    public const string TRANSLATION_KEY = 'sociallogin.error.provider_communication';

    public function __construct(public readonly string $providerCode, ?\Throwable $previous = null)
    {
        parent::__construct(self::TRANSLATION_KEY, 0, $previous);
    }
}
