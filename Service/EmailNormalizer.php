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

namespace SocialLogin\Service;

/**
 * The one form an address is stored and compared in.
 *
 * Providers hand the same mailbox back in different shapes — a capitalised domain, a
 * stray space from a form — and `customer.email` is matched on exactly, with a
 * case-insensitive collation that a leading space still defeats. Normalising in one place
 * is what keeps "the address already has an account" from depending on which provider
 * asked, and stops a second account being opened on the same mailbox spelled differently.
 *
 * Only the two transformations that are safe for every mailbox: the case of the whole
 * address, which no mail system in practice distinguishes, and surrounding whitespace,
 * which is never part of one. Nothing is stripped inside the local part — a dot or a
 * `+tag` belongs to its owner and can address a different mailbox.
 */
final readonly class EmailNormalizer
{
    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function normalizeOrNull(?string $email): ?string
    {
        if (null === $email) {
            return null;
        }

        $normalized = self::normalize($email);

        return '' === $normalized ? null : $normalized;
    }
}
