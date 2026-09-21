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

namespace SocialLogin\Controller\Admin;

use Propel\Runtime\Propel;
use Psr\Log\LoggerInterface;
use SocialLogin\Exception\SocialLoginNotices;
use SocialLogin\Form\SocialLoginConfigurationForm;
use SocialLogin\Provider\ProviderRegistry;
use SocialLogin\Provider\SocialLoginProviderInterface;
use SocialLogin\SocialLogin;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Controller\Admin\AdminController;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Template\ParserContext;
use Thelia\Form\BaseForm;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Model\Map\ModuleConfigTableMap;
use Thelia\Model\ModuleConfigQuery;

#[Route('/admin/module/SocialLogin', name: 'sociallogin_config_')]
final class ConfigurationController extends AdminController
{
    #[Route('/configuration', name: 'configuration', methods: 'POST')]
    public function saveConfiguration(
        ParserContext $parserContext,
        ProviderRegistry $providerRegistry,
        LoggerInterface $logger,
    ): RedirectResponse|Response|null {
        if (null !== $response = $this->checkAuth([AdminResources::MODULE], ['SocialLogin'], AccessManager::UPDATE)) {
            return $response;
        }

        $form = $this->createForm(SocialLoginConfigurationForm::getName());

        try {
            $data = $this->validateForm($form)->getData();

            $this->saveProviderConfiguration($data, $providerRegistry);

            return $this->generateSuccessRedirect($form);
        } catch (FormValidationException $e) {
            $errorMessage = $this->createStandardFormValidationErrorMessage($e);
        } catch (\Exception $e) {
            // The admin sees a generic, translated message; the exception itself
            // (provider, cause) is only ever written to the log, never to the screen.
            $logger->error('Social login: configuration save failed.', ['exception' => $e]);

            $errorMessage = $this->getTranslator()->trans(SocialLoginNotices::UNEXPECTED_ERROR, [], SocialLogin::DOMAIN_NAME);
        }

        // The error page is reached through a redirect, so nothing kept in this request
        // survives it: the message goes through the session twice. As a flash, which the
        // back office layout prints above the page; and with the submitted form, which the
        // configuration hook restores (ConfigurationHook) so the other fields keep what was
        // typed and the refused field shows its own error. Nothing was written: the save
        // only runs once the whole form is valid.
        $form->setErrorMessage($errorMessage);
        $parserContext->addForm($form);
        $this->stripSecretFieldsFromSessionForm($parserContext, $form, $providerRegistry);
        $this->addFlash('danger', $errorMessage);

        return $this->generateErrorRedirect($form);
    }

    /**
     * {@see ParserContext::addForm()} stores the whole submitted
     * form data in session, as-is, so the redisplay after a refused save can restore what
     * the admin typed. A secret typed on this very screen is part of that data: without
     * this, it sits in clear text wherever the session is persisted (file, database,
     * Redis) until it expires or a later save overwrites it. The form itself never echoes
     * a secret back (it always renders empty, see `SocialLoginConfigurationForm::addSecretField()`),
     * so blanking it here costs nothing on redisplay.
     */
    private function stripSecretFieldsFromSessionForm(ParserContext $parserContext, BaseForm $form, ProviderRegistry $providerRegistry): void
    {
        $session = $parserContext->getSession();
        $formErrorInformation = $session->getFormErrorInformation();
        $formKey = $form::class.':'.$form->getType();

        if (!isset($formErrorInformation[$formKey]['data']) || !\is_array($formErrorInformation[$formKey]['data'])) {
            return;
        }

        foreach ($providerRegistry->getAllProviders() as $provider) {
            foreach ($provider->getSecretFieldNames() as $secretFieldName) {
                $formErrorInformation[$formKey]['data'][$secretFieldName] = '';
            }
        }

        $session->setFormErrorInformation($formErrorInformation);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function saveProviderConfiguration(array $data, ProviderRegistry $providerRegistry): void
    {
        // One transaction around every provider's fields: setConfigValue() saves each row
        // through the same write connection Propel caches for this database, so its own
        // per-row transaction only nests inside this one and this one alone commits or
        // rolls back. A failure part-way through must not leave one provider's fields
        // written while another's are refused.
        $con = Propel::getWriteConnection(ModuleConfigTableMap::DATABASE_NAME);
        $con->beginTransaction();

        try {
            foreach ($providerRegistry->getAllProviders() as $provider) {
                $this->saveProviderFields($provider, $data);
            }

            $con->commit();
        } catch (\Throwable $throwable) {
            $con->rollBack();
            ModuleConfigQuery::resetConfigCache();

            throw $throwable;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function saveProviderFields(SocialLoginProviderInterface $provider, array $data): void
    {
        // The provider classifies its own keys: the switch is saved as a boolean, every
        // credential as a string, secrets only when the admin actually typed one, and the
        // logo on its own because it is optional and never part of "is this configured".
        // The logo arrives already sanitized, or empty to clear it: the form turned the
        // paste into what the storefront will print (SocialLoginConfigurationForm), and an
        // empty value makes the storefront fall back to the theme's built-in brand mark.
        SocialLogin::setConfigValue(
            $provider->getEnabledFieldName(),
            true === ($data[$provider->getEnabledFieldName()] ?? false) ? '1' : '0',
        );

        $secretFieldNames = $provider->getSecretFieldNames();

        foreach ($provider->getCredentialFieldNames() as $fieldName) {
            $submittedValue = (string) ($data[$fieldName] ?? '');

            if (\in_array($fieldName, $secretFieldNames, true)) {
                $this->setSecretConfigValueUnlessEmpty($fieldName, $submittedValue);

                continue;
            }

            SocialLogin::setConfigValue($fieldName, $submittedValue);
        }

        SocialLogin::setConfigValue($provider->getLogoFieldName(), (string) ($data[$provider->getLogoFieldName()] ?? ''));

        // The display order arrives as an integer the form already checked, or null when
        // the field was left empty, which is stored as empty: the provider goes back to
        // the default order.
        $position = $data[$provider->getPositionFieldName()] ?? null;
        SocialLogin::setConfigValue($provider->getPositionFieldName(), \is_int($position) ? (string) $position : '');
    }

    /**
     * A secret field submitted empty means "keep the existing value": the form never
     * carries the stored secret back to the browser (see SocialLoginConfigurationForm),
     * so an empty submission is never treated as "clear the secret".
     */
    private function setSecretConfigValueUnlessEmpty(string $configKey, string $submittedValue): void
    {
        if ('' === trim($submittedValue)) {
            return;
        }

        SocialLogin::setConfigValue($configKey, $submittedValue);
    }
}
