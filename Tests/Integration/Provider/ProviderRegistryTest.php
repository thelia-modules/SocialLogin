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

namespace SocialLogin\Tests\Integration\Provider;

use SocialLogin\DTO\VerifiedIdentity;
use SocialLogin\Provider\ProviderConfigurationKeys;
use SocialLogin\Provider\ProviderRegistry;
use SocialLogin\Provider\SocialLoginProviderInterface;
use SocialLogin\Service\SocialLoginConfiguration;
use SocialLogin\SocialLogin;
use Thelia\Model\ModuleConfigQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * The order the registry hands providers out in is the one the storefront buttons and the
 * back office blocks are shown in. Providers are doubles registered in a known order, so
 * what is proved is the sort itself, not the order the container happens to tag them in;
 * their positions are real settings, read through the real reader.
 */
final class ProviderRegistryTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();

        ModuleConfigQuery::resetConfigCache();
    }

    public function testProvidersComeOutByIncreasingPosition(): void
    {
        $this->setPositions(['alpha' => '3', 'bravo' => '1', 'charlie' => '2']);

        self::assertSame(['bravo', 'charlie', 'alpha'], array_keys($this->registry()->getAllProviders()));
    }

    public function testWithoutAnyPositionTheRegistrationOrderIsKept(): void
    {
        self::assertSame(['alpha', 'bravo', 'charlie'], array_keys($this->registry()->getAllProviders()));
    }

    public function testProvidersWithAPositionComeBeforeThoseWithout(): void
    {
        $this->setPositions(['alpha' => '', 'bravo' => 'not a number', 'charlie' => '5']);

        self::assertSame(['charlie', 'alpha', 'bravo'], array_keys($this->registry()->getAllProviders()));
    }

    public function testProvidersSharingAPositionKeepTheRegistrationOrder(): void
    {
        $this->setPositions(['alpha' => '2', 'bravo' => '1', 'charlie' => '1']);

        self::assertSame(['bravo', 'charlie', 'alpha'], array_keys($this->registry()->getAllProviders()));
    }

    public function testConfiguredProvidersAreSortedToo(): void
    {
        $this->setPositions(['alpha' => '2', 'bravo' => '9', 'charlie' => '1']);

        self::assertSame(['charlie', 'alpha'], array_keys($this->registry(notConfigured: ['bravo'])->getConfiguredProviders()));
    }

    /**
     * @param array<string, string> $positions provider code => stored position
     */
    private function setPositions(array $positions): void
    {
        foreach ($positions as $code => $position) {
            SocialLogin::setConfigValue($code.'_position', $position);
        }
    }

    /**
     * @param list<string> $notConfigured
     */
    private function registry(array $notConfigured = []): ProviderRegistry
    {
        $configuration = $this->getService(SocialLoginConfiguration::class);

        $providers = array_map(
            fn (string $code): SocialLoginProviderInterface => $this->provider($code, !\in_array($code, $notConfigured, true), $configuration),
            ['alpha', 'bravo', 'charlie'],
        );

        return new ProviderRegistry($providers, $configuration);
    }

    private function provider(string $code, bool $configured, SocialLoginConfiguration $configuration): SocialLoginProviderInterface
    {
        return new readonly class($code, $configured, $configuration) implements SocialLoginProviderInterface {
            use ProviderConfigurationKeys;

            public function __construct(
                private string $code,
                private bool $configured,
                private SocialLoginConfiguration $configuration,
            ) {
            }

            public function getCode(): string
            {
                return $this->code;
            }

            public function getLabel(): string
            {
                return ucfirst($this->code);
            }

            public function getCredentialFieldNames(): array
            {
                return [$this->code.'_client_id'];
            }

            public function getSecretFieldNames(): array
            {
                return [];
            }

            public function isConfigured(): bool
            {
                return $this->configured;
            }

            public function getAuthorizationUrl(string $state, string $callbackUrl): string
            {
                throw new \LogicException('Not reached by these tests.');
            }

            public function fetchVerifiedIdentity(array $callbackParameters, string $callbackUrl): VerifiedIdentity
            {
                throw new \LogicException('Not reached by these tests.');
            }

            protected function getConfiguration(): SocialLoginConfiguration
            {
                return $this->configuration;
            }
        };
    }
}
