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

use SocialLogin\Form\SocialLoginConfigurationForm;
use SocialLogin\Provider\ProviderRegistry;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Form\TheliaFormFactory;
use Thelia\Core\Hook\BaseHook;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Core\Template\ParserContext;

final class ConfigurationHook extends BaseHook
{
    public function __construct(
        private readonly TheliaFormFactory $formFactory,
        private readonly ParserContext $parserContext,
        private readonly ProviderRegistry $providerRegistry,
        private readonly UrlGeneratorInterface $urlGenerator,
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
            'module.configuration' => [
                [
                    'type' => 'back',
                    'method' => 'onModuleConfiguration',
                ],
            ],
        ];
    }

    public function onModuleConfiguration(HookRenderEvent $event): void
    {
        // After a refused save the controller redirects here with the submitted form kept
        // in the session: restored, it carries what was typed and each field's error, so
        // the refusal is shown under the field it concerns instead of being lost.
        $form = $this->parserContext->getForm(
            SocialLoginConfigurationForm::getName(),
            SocialLoginConfigurationForm::class,
            FormType::class,
        ) ?? $this->formFactory->createForm(SocialLoginConfigurationForm::getName());
        $form->createView();

        $event->add(
            $this->render('SocialLogin/module_configuration.html.twig', [
                'configuration_form' => $form->getView(),
                'providers' => $this->providersForTemplate(),
            ])
        );
    }

    /**
     * Everything the template needs, one entry per provider the registry knows about —
     * configured or not, so a provider the shop has not turned on yet still gets its
     * section and its callback URL to declare with the provider's console.
     *
     * @return array<string, array{label: string, enabledFieldName: string, logoFieldName: string, positionFieldName: string, credentialFieldNames: list<string>, secretFieldNames: list<string>, callbackUrl: string}>
     */
    private function providersForTemplate(): array
    {
        $providers = [];

        foreach ($this->providerRegistry->getAllProviders() as $code => $provider) {
            // Providers arrive already sorted by display order. The template renders each
            // one's toggle, display order and logo itself, and loops over the credentials the
            // provider names.
            $providers[$code] = [
                'label' => $provider->getLabel(),
                'enabledFieldName' => $provider->getEnabledFieldName(),
                'logoFieldName' => $provider->getLogoFieldName(),
                'positionFieldName' => $provider->getPositionFieldName(),
                'credentialFieldNames' => $provider->getCredentialFieldNames(),
                'secretFieldNames' => $provider->getSecretFieldNames(),
                'callbackUrl' => $this->callbackUrl($code),
            ];
        }

        return $providers;
    }

    /**
     * Built from the route the front callback controller actually declares, not from a
     * concatenated path: the route can move, the URL shown here follows it.
     */
    private function callbackUrl(string $providerCode): string
    {
        return $this->urlGenerator->generate(
            'sociallogin_callback',
            ['provider' => $providerCode],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }
}
