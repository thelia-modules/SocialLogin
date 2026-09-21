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

namespace SocialLogin\DTO;

use Symfony\Component\HttpFoundation\Cookie;

/**
 * The pair a departure hands out: the value that travels to the provider inside the
 * `state` parameter, and the cookie that stays on the browser and is the other half
 * of it. Both are needed to come back, which is what makes a replayed `state` useless.
 *
 * The cookie is returned rather than set here: only the controller holds the Response.
 */
final readonly class StateChallenge
{
    public function __construct(
        public string $state,
        public Cookie $cookie,
    ) {
    }
}
