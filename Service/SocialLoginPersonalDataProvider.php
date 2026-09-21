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

use SocialLogin\Model\SocialLoginIdentity;
use Thelia\Domain\Customer\Service\CustomerPersonalDataProviderInterface;
use Thelia\Model\Customer;

/**
 * Declares the shop accounts a customer opened through a provider.
 *
 * The provider's own stable subject (provider_identifier) never leaves this module: it
 * identifies the pair to the shop, not the person, and handing it out would only give
 * whoever reads the export something to try elsewhere as a credential. What the export
 * carries is what the account holder can already see on their own identity list: which
 * provider, the address it last reported, and the two dates.
 *
 * Anonymizing a customer takes their identities with them. The whole point of matching
 * on (provider, provider_identifier) rather than on the address is that the pair belongs
 * to this account and no other; leaving the rows behind once the account they point to
 * has been erased would keep a dangling reference to nothing.
 */
final readonly class SocialLoginPersonalDataProvider implements CustomerPersonalDataProviderInterface
{
    public function __construct(
        private SocialLoginIdentityRepository $identityRepository,
    ) {
    }

    public function getPersonalDataSectionName(): string
    {
        return 'sociallogin';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function exportPersonalData(Customer $customer): array
    {
        $identities = [];

        foreach ($this->identityRepository->findForCustomer((int) $customer->getId()) as $identity) {
            $identities[] = $this->exportIdentity($identity);
        }

        return $identities;
    }

    public function anonymizePersonalData(Customer $customer): void
    {
        $this->identityRepository->deleteForCustomer((int) $customer->getId());
    }

    /**
     * @return array<string, mixed>
     */
    private function exportIdentity(SocialLoginIdentity $identity): array
    {
        return [
            'provider' => $identity->getProvider(),
            'email' => $identity->getEmail(),
            'last_login_at' => $this->formatDate($identity->getLastLoginAt()),
            'created_at' => $this->formatDate($identity->getCreatedAt()),
            'updated_at' => $this->formatDate($identity->getUpdatedAt()),
        ];
    }

    private function formatDate(mixed $date): ?string
    {
        return $date instanceof \DateTimeInterface ? $date->format(\DATE_ATOM) : null;
    }
}
