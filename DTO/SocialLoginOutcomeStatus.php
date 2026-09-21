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

enum SocialLoginOutcomeStatus: string
{
    /** The customer is signed in; nothing is left for the caller to ask. */
    case LoggedIn = 'logged_in';

    /**
     * The address the provider vouched for already belongs to an account this identity
     * is not linked to. Nobody is signed in: the caller must ask for that account's
     * password before the identity is attached to it.
     */
    case AttachmentRequired = 'attachment_required';

    /**
     * The address belongs to the passwordless record a guest order hangs off. The core
     * opens such a record on its activation code alone, on purpose, because the record
     * carries the order history of everyone who ever ordered on that address. Social
     * login does not get to shortcut that: the caller sends the visitor to the shop's
     * own activation flow.
     */
    case AccountActivationRequired = 'account_activation_required';
}
