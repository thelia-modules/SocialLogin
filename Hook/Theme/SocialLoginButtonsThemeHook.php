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

namespace SocialLogin\Hook\Theme;

use SocialLogin\Exception\EmailNotProvidedException;
use SocialLogin\Exception\EmailNotVerifiedException;
use SocialLogin\Exception\IdentityTokenVerificationException;
use SocialLogin\Exception\InvalidStateException;
use SocialLogin\Exception\OrphanedIdentityException;
use SocialLogin\Exception\ProviderCommunicationException;
use SocialLogin\Exception\ProviderNotConfiguredException;
use SocialLogin\Exception\SocialLoginNotices;
use SocialLogin\Exception\UnknownProviderException;
use SocialLogin\Provider\ProviderRegistry;
use SocialLogin\Service\SocialLoginConfiguration;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Hook\Theme\ThemeHookInterface;
use Twig\Environment;

/**
 * The "continue with a provider" buttons offered next to the login, the registration
 * and the checkout identification forms.
 *
 * Nothing here reaches a provider: {@see ProviderRegistry::getConfiguredProviders()}
 * only reads what the shop configured, so a page with no provider turned on renders
 * exactly as it did before this module existed — the recette's first check.
 */
final readonly class SocialLoginButtonsThemeHook implements ThemeHookInterface
{
    private const array SUPPORTED_HOOKS = [
        'login.form.bottom',
        'register.form.bottom',
        'checkout-identify.form.bottom',
    ];

    /**
     * The only values a `sociallogin_notice` query parameter may carry into the login
     * page: the keys the callback emits on its session-free refusal path (Apple's
     * cross-site POST cannot use a flash). Anything else renders nothing.
     *
     * Built from the exceptions {@see \SocialLogin\Controller\Front\SocialLoginController::callback()}
     * can let through to `loginErrorResponse()` — every SocialLoginException reachable
     * from state validation, provider resolution, the provider exchange and
     * `SocialLoginService::completeLogin()`'s email checks — plus the two outcomes that
     * are not exceptions, so a key renamed on either side cannot silently fall out of
     * this allow-list.
     */
    private const array ALLOWED_NOTICE_KEYS = [
        UnknownProviderException::TRANSLATION_KEY,
        ProviderNotConfiguredException::TRANSLATION_KEY,
        InvalidStateException::TRANSLATION_KEY,
        ProviderCommunicationException::TRANSLATION_KEY,
        IdentityTokenVerificationException::TRANSLATION_KEY,
        EmailNotProvidedException::TRANSLATION_KEY,
        EmailNotVerifiedException::TRANSLATION_KEY,
        OrphanedIdentityException::TRANSLATION_KEY,
        SocialLoginNotices::UNEXPECTED_ERROR,
        SocialLoginNotices::ACCOUNT_ACTIVATION_REQUIRED,
    ];

    public function __construct(
        private Environment $twig,
        private ProviderRegistry $providerRegistry,
        private RequestStack $requestStack,
        private SocialLoginConfiguration $configuration,
    ) {
    }

    public function supports(string $hookName): bool
    {
        return \in_array($hookName, self::SUPPORTED_HOOKS, true);
    }

    public function render(string $hookName, array $parameters): string
    {
        $providers = [];

        foreach ($this->providerRegistry->getConfiguredProviders() as $code => $provider) {
            // The logo is sanitized here, before it ever reaches the template: the button
            // component renders it inline with |raw and does not re-sanitize. A merchant
            // that pasted no logo gets null, and the theme falls back to its built-in mark.
            $providers[$code] = [
                'code' => $code,
                'label' => $provider->getLabel(),
                'logo' => $this->configuration->getCustomLogo($provider->getLogoFieldName()),
            ];
        }

        $notice = 'login.form.bottom' === $hookName ? $this->notice() : null;

        if ([] === $providers && null === $notice) {
            return '';
        }

        return $this->twig->render('@SocialLoginModule/theme-hook/social_login_buttons.html.twig', [
            'providers' => $providers,
            'notice' => $notice,
            // The checkout identification page is the only caller that hands this hook a
            // destination: {@see \Thelia\Domain\Customer\Service\AuthenticationReturnUrl}
            // reads it back as the "redirect" parameter once the visitor returns.
            'nextStepUrl' => \is_string($parameters['next_step_url'] ?? null) ? $parameters['next_step_url'] : null,
        ]);
    }

    private function notice(): ?string
    {
        $notice = $this->requestStack->getCurrentRequest()?->query->get('sociallogin_notice');

        return \is_string($notice) && \in_array($notice, self::ALLOWED_NOTICE_KEYS, true) ? $notice : null;
    }
}
