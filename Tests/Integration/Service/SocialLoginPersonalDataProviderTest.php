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

namespace SocialLogin\Tests\Integration\Service;

use SocialLogin\DTO\VerifiedIdentity;
use SocialLogin\Service\SocialLoginIdentityRepository;
use Thelia\Domain\Customer\Service\CustomerAnonymizer;
use Thelia\Domain\Customer\Service\CustomerPersonalDataExporter;
use Thelia\Test\IntegrationTestCase;

/**
 * Goes through the real container rather than instantiating SocialLoginPersonalDataProvider
 * directly, because what is actually being proved here is the wiring: that the provider
 * is picked up by CustomerPersonalDataExporter's and CustomerAnonymizer's
 * `#[AutowireIterator('thelia.customer.personal_data_provider')]`, on the strength of
 * implementing CustomerPersonalDataProviderInterface alone — nothing in this module
 * tags it by hand.
 */
final class SocialLoginPersonalDataProviderTest extends IntegrationTestCase
{
    public function testAnonymizingACustomerDeletesTheirIdentityRows(): void
    {
        $factory = $this->createFixtureFactory();
        $customer = $factory->customer($factory->customerTitle());
        $identities = new SocialLoginIdentityRepository();
        $identities->link($customer, new VerifiedIdentity('google', 'sub-anon', 'anon@test.com', true));

        $this->getService(CustomerAnonymizer::class)->anonymize($customer);

        self::assertCount(0, $identities->findForCustomer($customer->getId()));
    }

    public function testExportingACustomerListsTheirIdentities(): void
    {
        $factory = $this->createFixtureFactory();
        $customer = $factory->customer($factory->customerTitle());
        $identities = new SocialLoginIdentityRepository();
        $identities->link($customer, new VerifiedIdentity('google', 'sub-export', 'export@test.com', true));

        $personalData = $this->getService(CustomerPersonalDataExporter::class)->export($customer);

        $socialLoginSectionNames = array_diff(array_keys($personalData), CustomerPersonalDataExporter::CORE_SECTION_NAMES);
        self::assertCount(1, $socialLoginSectionNames);

        $section = $personalData[array_values($socialLoginSectionNames)[0]];
        self::assertCount(1, $section);
        self::assertSame('google', $section[0]['provider']);
        self::assertSame('export@test.com', $section[0]['email']);
        self::assertNotNull($section[0]['created_at']);
    }
}
