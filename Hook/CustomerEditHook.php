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

namespace SocialLogin\Hook;

use SocialLogin\Provider\ProviderRegistry;
use SocialLogin\Service\SocialLoginIdentityRepository;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;
use Thelia\Core\Template\Parser\ParserResolver;

/**
 * Back office hooks read {@see SocialLoginIdentityRepository} directly rather than
 * through {@see \SocialLogin\Service\IdentityDetachmentService}: there is no
 * back-office-specific rule to apply on top of the plain list — that is what the service
 * exists for on the front, where {@see Theme\SocialLoginAccountThemeHook}
 * also needs the detachment and password-confirmation behaviour the repository alone
 * does not carry. One admin screen going through the repository and the other through
 * the service would say nothing about the back office; it would only say which of the
 * two happened to be built first.
 */
final class CustomerEditHook extends BaseHook
{
    public function __construct(
        private readonly ProviderRegistry $providerRegistry,
        private readonly SocialLoginIdentityRepository $identityRepository,
        ?EventDispatcherInterface $dispatcher = null,
        ?ParserResolver $parserResolver = null,
    ) {
        parent::__construct($dispatcher, $parserResolver);
    }

    /**
     * @return array<string, list<array<string, string>>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'customer-edit.top' => [
                [
                    'type' => 'back',
                    'method' => 'onCustomerEditTop',
                ],
            ],
        ];
    }

    /**
     * The customer/edit.html.twig template calls
     * safe_hook('customer-edit.top', { customer_id: customer.id }), so the only
     * argument this listener can rely on is customer_id.
     */
    public function onCustomerEditTop(HookRenderEvent $event): void
    {
        $customerId = (int) $event->getArgument('customer_id', '0');

        if ($customerId <= 0) {
            return;
        }

        $identities = $this->identityRepository->findForCustomer($customerId);

        if ([] === $identities) {
            return;
        }

        $event->add(
            $this->render('SocialLogin/customer_edit_identities.html.twig', [
                'identities' => $identities,
                'providerLabels' => $this->providerLabels(),
            ])
        );
    }

    /**
     * Labels for the providers the registry knows today. An identity whose provider is
     * not in this map — one the shop has since removed — is not this map's problem: the
     * template falls back to the raw code for it.
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
