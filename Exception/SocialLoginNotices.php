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
 * The two `sociallogin_notice` translation keys that are not an exception: an outcome
 * the controller reports on its own, so {@see SocialLoginException} is not where they
 * belong. Kept as constants for the same reason every exception carries its own
 * `TRANSLATION_KEY` — a literal repeated at every call site is a key that can drift from
 * the one the templates' allow-lists actually check for.
 */
final class SocialLoginNotices
{
    public const string ACCOUNT_ACTIVATION_REQUIRED = 'sociallogin.notice.account_activation_required';
    public const string UNEXPECTED_ERROR = 'sociallogin.error.unexpected';

    private function __construct()
    {
    }
}
