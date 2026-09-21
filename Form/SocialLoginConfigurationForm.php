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

namespace SocialLogin\Form;

use SocialLogin\Provider\ProviderRegistry;
use SocialLogin\Service\SocialLoginConfiguration;
use SocialLogin\Service\SvgLogoSanitizer;
use SocialLogin\SocialLogin;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints\PositiveOrZero;
use Thelia\Core\Translation\Translator;
use Thelia\Form\BaseForm;

final class SocialLoginConfigurationForm extends BaseForm
{
    /**
     * Shown under the logo field when what was pasted survives sanitization as nothing at
     * all: the paste is refused instead of being stored as a value the storefront would
     * silently ignore.
     */
    public const string INVALID_LOGO_SVG_MESSAGE = 'sociallogin.error.invalid_logo_svg';

    /**
     * Shown under the logo field when the paste, or what sanitization makes of it, would not
     * fit in the column that stores it.
     */
    public const string LOGO_SVG_TOO_LARGE_MESSAGE = 'sociallogin.error.logo_svg_too_large';

    /**
     * Shown under the display order field when what was typed is not a whole number of
     * zero or more, whether the browser let a negative number or a word through.
     */
    public const string INVALID_POSITION_MESSAGE = 'sociallogin.error.invalid_position';

    /**
     * Human labels for the configuration keys a provider enumerates. A key with no entry
     * here still gets a field, humanized from its name, so a provider added later is
     * never left unconfigurable for want of a label here.
     */
    private const array FIELD_LABELS = [
        'google_client_id' => 'Client ID',
        'google_client_secret' => 'Client secret',
        'facebook_client_id' => 'App ID',
        'facebook_client_secret' => 'App secret',
        'apple_client_id' => 'Service ID',
        'apple_team_id' => 'Team ID',
        'apple_key_id' => 'Key ID',
        'apple_private_key' => 'Private key (.p8 content)',
    ];

    /**
     * `module_config_i18n.value` is a TEXT column, which holds 65535 *bytes*, so that is
     * what the screen refuses beyond, with a message, rather than letting MySQL truncate
     * the value or refuse the write halfway through the save. Counted in bytes and not in
     * characters for the same reason: the column's limit is not a character count.
     */
    private const int LOGO_MAXIMUM_BYTES = 65535;

    public function __construct(
        private readonly ProviderRegistry $providerRegistry,
        private readonly SocialLoginConfiguration $configuration,
        private readonly SvgLogoSanitizer $svgLogoSanitizer,
    ) {
    }

    public static function getName(): string
    {
        return 'sociallogin_configuration_form';
    }

    protected function buildForm(): void
    {
        foreach ($this->providerRegistry->getAllProviders() as $code => $provider) {
            $secretFieldNames = $provider->getSecretFieldNames();

            // The provider classifies its own keys — switch, credentials, logo — and this
            // screen renders one shape per class rather than re-deriving the classification.
            $this->addProviderEnabledField($provider->getEnabledFieldName(), $provider->getLabel());

            foreach ($provider->getCredentialFieldNames() as $fieldName) {
                if (\in_array($fieldName, $secretFieldNames, true)) {
                    $this->addSecretField($fieldName, $this->labelFor($fieldName, $code));

                    continue;
                }

                $this->addConfigurationField($fieldName, $this->labelFor($fieldName, $code));
            }

            $this->addLogoField($provider->getLogoFieldName(), $provider->getLabel());
            $this->addPositionField($provider->getPositionFieldName());
        }
    }

    /**
     * Where the provider appears among the others, on the storefront and on this screen.
     * Optional: left empty, the provider comes after those that have one, in the default
     * order. The same message covers a negative number (the constraint) and something that
     * is not a number at all (the transformation), since the fix is the same.
     */
    private function addPositionField(string $fieldName): void
    {
        $translator = Translator::getInstance();
        $invalidPositionMessage = $translator->trans(self::INVALID_POSITION_MESSAGE, [], SocialLogin::DOMAIN_NAME);

        $this->formBuilder->add(
            $fieldName,
            IntegerType::class,
            [
                'required' => false,
                'label' => $translator->trans('Display order', [], SocialLogin::DOMAIN_NAME),
                'help' => $translator->trans('Lower numbers appear first.', [], SocialLogin::DOMAIN_NAME),
                'attr' => ['min' => 0],
                'constraints' => [new PositiveOrZero(message: $invalidPositionMessage)],
                'invalid_message' => $invalidPositionMessage,
                'data' => $this->initialValue($fieldName, $this->configuration->getPosition($fieldName)),
            ]
        );
    }

    /**
     * The merchant's own copy of the provider's official logo, pasted as SVG. Optional and
     * never a secret: it is prefilled with the stored value so an existing logo can be seen
     * and edited, and left empty means "use the theme's built-in brand mark". It is
     * deliberately not one of the provider's credentials, so it never weighs on whether the
     * provider counts as configured. It carries a real label, not `false`: the label is
     * what a refusal names in the message shown above the page, which would otherwise
     * read the technical field name.
     *
     * The field's data is the sanitized markup, not the paste: sanitization happens once,
     * here, and what the form hands over is exactly what gets stored.
     */
    private function addLogoField(string $fieldName, string $providerLabel): void
    {
        $this->formBuilder->add(
            $fieldName,
            TextareaType::class,
            [
                'required' => false,
                'label' => Translator::getInstance()->trans(
                    '%provider% logo (SVG)',
                    ['%provider%' => $providerLabel],
                    SocialLogin::DOMAIN_NAME
                ),
                'data' => $this->initialValue($fieldName, $this->configuration->get($fieldName) ?? ''),
            ]
        );

        $this->formBuilder->get($fieldName)->addModelTransformer(new CallbackTransformer(
            static fn (?string $storedValue): string => $storedValue ?? '',
            $this->sanitizeSubmittedLogo(...),
        ));
    }

    /**
     * A refusal is a transformation failure rather than a constraint: the paste is known
     * here and nowhere after, since the field's data is what sanitization made of it. The
     * failure carries its own message and lands on the field, and the constraints of a
     * field that failed to transform are never run.
     *
     * Empty stays valid: it is how a merchant goes back to the theme's built-in brand mark.
     * A paste that sanitizes to nothing is refused rather than stored: saved in silence, it
     * would look accepted in the screen and render the built-in mark on the storefront,
     * which is the worst of both. Size is checked twice against the column: on the paste,
     * before any parsing, and on the sanitized markup, which comes out larger than it went
     * in (reindented, self-closing tags expanded) and is what is actually written.
     */
    private function sanitizeSubmittedLogo(?string $submittedValue): string
    {
        if (null === $submittedValue || '' === trim($submittedValue)) {
            return '';
        }

        if (\strlen($submittedValue) > self::LOGO_MAXIMUM_BYTES) {
            throw $this->logoRefusal(self::LOGO_SVG_TOO_LARGE_MESSAGE);
        }

        $sanitized = $this->svgLogoSanitizer->sanitize($submittedValue);

        if (null === $sanitized) {
            throw $this->logoRefusal(self::INVALID_LOGO_SVG_MESSAGE);
        }

        $sanitizedValue = (string) $sanitized;

        if (\strlen($sanitizedValue) > self::LOGO_MAXIMUM_BYTES) {
            throw $this->logoRefusal(self::LOGO_SVG_TOO_LARGE_MESSAGE);
        }

        return $sanitizedValue;
    }

    private function logoRefusal(string $messageKey): TransformationFailedException
    {
        return new TransformationFailedException(
            message: $messageKey,
            invalidMessage: Translator::getInstance()->trans($messageKey, [], SocialLogin::DOMAIN_NAME),
        );
    }

    private function addProviderEnabledField(string $fieldName, string $providerLabel): void
    {
        $this->formBuilder->add(
            $fieldName,
            CheckboxType::class,
            [
                'required' => false,
                'label' => Translator::getInstance()->trans(
                    'Enable %provider% login',
                    ['%provider%' => $providerLabel],
                    SocialLogin::DOMAIN_NAME
                ),
                'data' => $this->initialValue($fieldName, $this->configuration->isEnabled($fieldName)),
            ]
        );
    }

    private function addConfigurationField(string $configKey, string $label): void
    {
        $this->formBuilder->add(
            $configKey,
            TextType::class,
            [
                'required' => false,
                'label' => $label,
                'data' => $this->initialValue($configKey, $this->configuration->get($configKey) ?? ''),
            ]
        );
    }

    /**
     * Secret fields never carry the stored value back to the browser: the field is
     * always rendered empty. The reminder that an empty submission keeps the current
     * value is carried by the template ({@see module_configuration.html.twig}), which
     * reads the Symfony `help` option — not `label_attr.help`, which the back office
     * theme's label component never reads, so setting it here only rendered an invalid
     * `help` HTML attribute on the `<label>`.
     *
     * See ConfigurationController::saveConfiguration() for the write side of "empty
     * means keep the current value".
     */
    private function addSecretField(string $configKey, string $label): void
    {
        $this->formBuilder->add(
            $configKey,
            $this->isMultilineSecret($configKey) ? TextareaType::class : TextType::class,
            [
                'required' => false,
                'label' => $label,
                'data' => '',
            ]
        );
    }

    /**
     * Every secret here is a one-line token except a private key file's content, which
     * a single-line input would cut off. Naming is the only signal available for it: no
     * method of the contract says a field's shape.
     */
    private function isMultilineSecret(string $configKey): bool
    {
        return str_ends_with($configKey, '_private_key');
    }

    /**
     * A field's `data` option wins over the data the form is created with, so a form
     * restored after a refused save (ParserContext::getForm(), which recreates it from
     * what was submitted) would show the stored values again and lose what was typed.
     * The submitted value is used when there is one. A field refused on submission is
     * not part of it, so it shows the stored value, which is what a new save would keep.
     * Secrets never go through here: they are never carried back to the browser.
     */
    private function initialValue(string $fieldName, mixed $storedValue): mixed
    {
        $initialData = $this->formBuilder->getData();

        if (\is_array($initialData) && \array_key_exists($fieldName, $initialData)) {
            return $initialData[$fieldName];
        }

        return $storedValue;
    }

    private function labelFor(string $fieldName, string $providerCode): string
    {
        $label = self::FIELD_LABELS[$fieldName] ?? $this->humanize($fieldName, $providerCode);

        return Translator::getInstance()->trans($label, [], SocialLogin::DOMAIN_NAME);
    }

    private function humanize(string $fieldName, string $providerCode): string
    {
        $withoutPrefix = str_starts_with($fieldName, $providerCode.'_')
            ? substr($fieldName, \strlen($providerCode) + 1)
            : $fieldName;

        return ucfirst(str_replace('_', ' ', $withoutPrefix));
    }
}
