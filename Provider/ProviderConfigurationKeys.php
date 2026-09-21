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

namespace SocialLogin\Provider;

use SocialLogin\Service\SocialLoginConfiguration;

/**
 * How a provider names its configuration keys, and what "configured" means, written once.
 *
 * Every provider names its switch `{code}_enabled`, its logo `{code}_logo_svg` and its
 * display order `{code}_position`, and every provider is configured when the switch is on and each of its credentials is
 * filled in. Copying that into each implementation is how two providers end up disagreeing
 * about their own keys, so it is derived here from the provider's code and from the one
 * list it does have to write for itself:
 * {@see SocialLoginProviderInterface::getCredentialFieldNames()}.
 *
 * A provider that genuinely names things differently simply overrides the method it needs
 * and keeps the rest.
 *
 * @phpstan-require-implements SocialLoginProviderInterface
 */
trait ProviderConfigurationKeys
{
    public function getEnabledFieldName(): string
    {
        return $this->getCode().'_enabled';
    }

    public function getLogoFieldName(): string
    {
        return $this->getCode().'_logo_svg';
    }

    public function getPositionFieldName(): string
    {
        return $this->getCode().'_position';
    }

    public function isConfigured(): bool
    {
        return $this->getConfiguration()->isEnabled($this->getEnabledFieldName())
            && $this->getConfiguration()->hasAll($this->getCredentialFieldNames());
    }

    /**
     * The settings reader the using class already holds. Asked for through a method rather
     * than read as a property so the trait states its own requirement instead of leaning on
     * a field name it cannot see.
     */
    abstract protected function getConfiguration(): SocialLoginConfiguration;
}
