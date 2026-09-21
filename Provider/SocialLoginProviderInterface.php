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

use SocialLogin\DTO\VerifiedIdentity;
use SocialLogin\Exception\IdentityTokenVerificationException;
use SocialLogin\Exception\ProviderCommunicationException;
use SocialLogin\Exception\ProviderNotConfiguredException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One external sign-in provider.
 *
 * A provider proves who the visitor is at the provider, and stops there. It opens no
 * session, reads no account, writes nothing: everything an implementation returns is a
 * claim about a person on a third-party service, and it is {@see \SocialLogin\Service\SocialLoginService}
 * that decides what a shop account may be done with it.
 *
 * Implementations must be inert until a sign-in actually happens: building one is done
 * on every request that lists the available providers, so no network call and no key
 * parsing belongs anywhere but inside the two methods below.
 */
#[AutoconfigureTag('sociallogin.provider')]
interface SocialLoginProviderInterface
{
    public function getCode(): string;

    /**
     * The provider's own name, as it brands it — shown on a button and on the back office
     * screen. Not translated: "Google" is "Google" in every locale.
     */
    public function getLabel(): string;

    /**
     * The configuration key that switches this provider on or off, named once here rather
     * than recomputed by every caller that needs it: a provider is free to name its own
     * switch however it likes, as long as it says so through this method. It is never one
     * of {@see getCredentialFieldNames()}.
     */
    public function getEnabledFieldName(): string;

    /**
     * The configuration key holding the merchant's own copy of the provider's official
     * logo, pasted as SVG in the back office. Optional and deliberately outside
     * {@see getCredentialFieldNames()}: a provider with no custom logo is still fully
     * configured — it just falls back to the theme's built-in brand mark. It is the
     * merchant, holding the provider account, who supplies the official artwork (Apple's
     * guidelines forbid recreating it), so this stays generic across every provider.
     */
    public function getLogoFieldName(): string;

    /**
     * The configuration key holding where the merchant wants this provider to appear among
     * the others, on the storefront buttons and on the back office screen alike. Optional
     * and, like the logo, outside {@see getCredentialFieldNames()}: an order left unset
     * never makes a provider any less configured, it only places it after those that
     * have one.
     */
    public function getPositionFieldName(): string;

    /**
     * Everything the shop has to obtain from the provider's console, in the order a screen
     * should show it — neither the on/off switch nor the logo, which have keys of their
     * own. This is the one list a provider writes for itself: the switch and the logo are
     * derived from its code, and "configured" means the switch is on and every key here is
     * filled in, so a screen, a save and a hook all read it here.
     *
     * @return list<string>
     */
    public function getCredentialFieldNames(): array;

    /**
     * The subset of {@see getCredentialFieldNames()} holding a secret: what a
     * screen must never echo back and a log must never carry.
     *
     * @return list<string>
     */
    public function getSecretFieldNames(): array;

    /**
     * Whether the shop turned this provider on and gave it every credential it needs.
     */
    public function isConfigured(): bool;

    /**
     * @param string $state       the opaque value {@see \SocialLogin\Service\StateManager} minted for this departure
     * @param string $callbackUrl absolute URL the provider sends the visitor back to, which must match the callback
     *                            URL passed to {@see fetchVerifiedIdentity()} exactly
     *
     * @throws ProviderNotConfiguredException
     */
    public function getAuthorizationUrl(string $state, string $callbackUrl): string;

    /**
     * Turn what came back from the provider into an identity, or refuse.
     *
     * @param array<string, mixed> $callbackParameters query or form parameters of the callback request
     *
     * @throws ProviderNotConfiguredException
     * @throws ProviderCommunicationException
     * @throws IdentityTokenVerificationException
     */
    public function fetchVerifiedIdentity(array $callbackParameters, string $callbackUrl): VerifiedIdentity;
}
