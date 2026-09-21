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

use Thelia\Model\Customer;

/**
 * Where a callback left the visitor, for a controller to turn into a page.
 */
final readonly class SocialLoginOutcome
{
    private function __construct(
        public SocialLoginOutcomeStatus $status,
        public ?Customer $customer,
        public ?string $email,
    ) {
    }

    public static function loggedIn(Customer $customer): self
    {
        return new self(SocialLoginOutcomeStatus::LoggedIn, $customer, $customer->getEmail());
    }

    public static function attachmentRequired(string $email): self
    {
        return new self(SocialLoginOutcomeStatus::AttachmentRequired, null, $email);
    }

    public static function accountActivationRequired(string $email): self
    {
        return new self(SocialLoginOutcomeStatus::AccountActivationRequired, null, $email);
    }
}
