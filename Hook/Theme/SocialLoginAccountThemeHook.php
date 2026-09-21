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

use SocialLogin\Exception\IdentityNotOwnedException;
use SocialLogin\Exception\InvalidPasswordException;
use SocialLogin\Exception\PasswordConfirmationRequiredException;
use SocialLogin\Exception\TooManyAttemptsException;
use SocialLogin\Provider\ProviderRegistry;
use SocialLogin\Service\IdentityDetachmentService;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Hook\Theme\ThemeHookInterface;
use Thelia\Core\Security\SecurityContext;
use Thelia\Model\Customer;
use Twig\Environment;

/**
 * The "social connections" section of the customer's own profile page.
 *
 * The theme hands this point a `customer` parameter built from the front API resource
 * (see the theme's account.html.twig), not the Propel entity the services here need, so
 * it is read again from {@see SecurityContext} instead of trusted from the parameters.
 */
final readonly class SocialLoginAccountThemeHook implements ThemeHookInterface
{
    /**
     * The only values a `sociallogin_notice` query parameter is allowed to carry into
     * the page: the module's own translation keys, plus the one success marker that is
     * not an exception. Anything else read from the URL is ignored rather than handed
     * to the translator.
     *
     * Built from the exceptions' own {@see \SocialLogin\Exception\SocialLoginException}
     * constants rather than repeated as literals, so a key renamed on the exception side
     * cannot silently fall out of this allow-list.
     */
    private const array ALLOWED_NOTICE_KEYS = [
        // The module's own success marker: nothing throws it.
        'detached',
        IdentityNotOwnedException::TRANSLATION_KEY,
        PasswordConfirmationRequiredException::TRANSLATION_KEY,
        InvalidPasswordException::TRANSLATION_KEY,
        TooManyAttemptsException::TRANSLATION_KEY,
    ];

    public function __construct(
        private Environment $twig,
        private IdentityDetachmentService $identityDetachmentService,
        private ProviderRegistry $providerRegistry,
        private SecurityContext $securityContext,
        private RequestStack $requestStack,
    ) {
    }

    public function supports(string $hookName): bool
    {
        return 'account.bottom' === $hookName;
    }

    public function render(string $hookName, array $parameters): string
    {
        $customer = $this->securityContext->getCustomerUser();

        if (!$customer instanceof Customer) {
            return '';
        }

        $identities = $this->identityDetachmentService->listFor($customer);
        $hasConfiguredProviders = [] !== $this->providerRegistry->getConfiguredProviders();

        // Nothing to say: no identity to list, and no provider a visitor could link one
        // from. A shop that later turns a provider on gets the section back on its own.
        if ([] === $identities && !$hasConfiguredProviders) {
            return '';
        }

        return $this->twig->render('@SocialLoginModule/theme-hook/social_login_account.html.twig', [
            'identities' => $identities,
            // Same rule as IdentityDetachmentService::requiresPasswordConfirmation(), on
            // the count already in hand: a second COUNT query would only ask the database
            // to confirm what listFor() just answered.
            'requiresPasswordConfirmation' => [] !== $identities && 1 >= \count($identities),
            'noticeKey' => $this->currentNoticeKey(),
            'providerLabels' => $this->providerLabels(),
        ]);
    }

    private function currentNoticeKey(): ?string
    {
        $notice = $this->requestStack->getCurrentRequest()?->query->get('sociallogin_notice');

        return \is_string($notice) && \in_array($notice, self::ALLOWED_NOTICE_KEYS, true) ? $notice : null;
    }

    /**
     * Labels for the providers the registry knows today, the same way
     * {@see \SocialLogin\Hook\CustomerEditHook} builds them for the back office: an
     * identity whose provider is not in this map — one the shop has since removed — is
     * not this map's problem, the template falls back to the raw code for it.
     *
     * @return array<string, string>
     */
    private function providerLabels(): array
    {
        $labels = [];

        foreach ($this->providerRegistry->getAllProviders() as $code => $provider) {
            $labels[$code] = $provider->getLabel();
        }

        return $labels;
    }
}
