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

namespace SocialLogin\Tests\Integration\Form;

use SocialLogin\Form\SocialLoginConfigurationForm;
use SocialLogin\SocialLogin;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Thelia\Core\Form\TheliaFormFactory;
use Thelia\Core\Translation\Translator;
use Thelia\Test\IntegrationTestCase;

/**
 * A logo the storefront could never show must not be saved as if it had been accepted.
 *
 * Sanitizing at read time alone made a bad paste look stored and then silently render the
 * built-in mark, with nothing on the screen saying why. The field is therefore sanitized
 * on submission, and what the form hands to the save is the sanitized markup, or a refusal
 * that says why.
 */
final class SocialLoginConfigurationFormTest extends IntegrationTestCase
{
    private const string LOGO_FIELD = 'google_logo_svg';

    private const string POSITION_FIELD = 'google_position';

    private const int LOGO_MAXIMUM_BYTES = 65535;

    public function testAPasteThatIsNotAnSvgIsRefused(): void
    {
        $form = $this->submit('<p>not a logo</p>');

        $this->assertRefusedWith(SocialLoginConfigurationForm::INVALID_LOGO_SVG_MESSAGE, $form);
    }

    /**
     * Nothing dangerous survives sanitization, so nothing at all is left: stored, it would
     * read as an accepted logo that never shows.
     */
    public function testAPasteThatSanitizesToNothingIsRefused(): void
    {
        $form = $this->submit('<script>alert(1)</script>');

        $this->assertRefusedWith(SocialLoginConfigurationForm::INVALID_LOGO_SVG_MESSAGE, $form);
    }

    public function testAValidSvgIsAccepted(): void
    {
        $form = $this->submit('<svg viewBox="0 0 24 24"><path d="M12 2 2 22h20z"/></svg>');

        self::assertCount(0, $form->get(self::LOGO_FIELD)->getErrors());
    }

    /**
     * What the save receives is what the storefront will print: the form is where the
     * paste is sanitized, once, and the controller stores the field's data as it is.
     */
    public function testTheSubmittedLogoComesOutSanitized(): void
    {
        $form = $this->submit('<svg viewBox="0 0 24 24" onload="alert(1)"><script>alert(1)</script><path d="M12 2 2 22h20z"/></svg>');

        $data = $form->get(self::LOGO_FIELD)->getData();

        self::assertTrue($form->get(self::LOGO_FIELD)->isValid());
        self::assertIsString($data);
        self::assertStringContainsStringIgnoringCase('<path', $data);
        self::assertStringNotContainsStringIgnoringCase('onload', $data);
        self::assertStringNotContainsStringIgnoringCase('<script', $data);
    }

    /**
     * Empty is how a merchant goes back to the theme's built-in brand mark.
     */
    public function testAnEmptyLogoIsAccepted(): void
    {
        $form = $this->submit('');

        self::assertCount(0, $form->get(self::LOGO_FIELD)->getErrors());
        self::assertSame('', $form->get(self::LOGO_FIELD)->getData());
    }

    /**
     * `module_config_i18n.value` is a TEXT column; beyond it the database would truncate
     * the paste and the merchant would never be told.
     */
    public function testAPasteLongerThanTheColumnIsRefused(): void
    {
        $filler = str_repeat('<path d="M0 0"/>', 5000);

        $form = $this->submit('<svg viewBox="0 0 24 24">'.$filler.'</svg>');

        self::assertGreaterThan(self::LOGO_MAXIMUM_BYTES, \strlen($filler));
        $this->assertRefusedWith(SocialLoginConfigurationForm::LOGO_SVG_TOO_LARGE_MESSAGE, $form);
    }

    /**
     * Sanitization reindents the markup and expands every self-closing tag, so a dense
     * paste that fits in the column comes out of it larger than the column: it is the
     * sanitized value that is written, so it is the one measured.
     */
    public function testAPasteThatOnlyOutgrowsTheColumnOnceSanitizedIsRefused(): void
    {
        $paste = '<svg viewBox="0 0 24 24">'.str_repeat('<path d="M0 0"/>', 3900).'</svg>';

        $form = $this->submit($paste);

        self::assertLessThan(self::LOGO_MAXIMUM_BYTES, \strlen($paste));
        $this->assertRefusedWith(SocialLoginConfigurationForm::LOGO_SVG_TOO_LARGE_MESSAGE, $form);
    }

    public function testAWholeNumberPositionIsAccepted(): void
    {
        $form = $this->submitFields([self::POSITION_FIELD => '2']);

        self::assertTrue($form->get(self::POSITION_FIELD)->isValid());
        self::assertSame(2, $form->get(self::POSITION_FIELD)->getData());
    }

    public function testANegativePositionIsRefusedWithAMessage(): void
    {
        $form = $this->submitFields([self::POSITION_FIELD => '-1']);

        $this->assertFieldRefusedWith(SocialLoginConfigurationForm::INVALID_POSITION_MESSAGE, $form, self::POSITION_FIELD);
    }

    public function testAPositionThatIsNotANumberIsRefusedWithAMessage(): void
    {
        $form = $this->submitFields([self::POSITION_FIELD => 'first']);

        $this->assertFieldRefusedWith(SocialLoginConfigurationForm::INVALID_POSITION_MESSAGE, $form, self::POSITION_FIELD);
    }

    /**
     * Empty is how a merchant goes back to the default order.
     */
    public function testAnEmptyPositionIsAccepted(): void
    {
        $form = $this->submitFields([self::POSITION_FIELD => '']);

        self::assertTrue($form->get(self::POSITION_FIELD)->isValid());
        self::assertNull($form->get(self::POSITION_FIELD)->getData());
    }

    private function assertRefusedWith(string $messageKey, FormInterface $form): void
    {
        $this->assertFieldRefusedWith($messageKey, $form, self::LOGO_FIELD);
    }

    private function assertFieldRefusedWith(string $messageKey, FormInterface $form, string $fieldName): void
    {
        $errors = iterator_to_array($form->get($fieldName)->getErrors(), false);
        $expectedMessage = Translator::getInstance()->trans($messageKey, [], SocialLogin::DOMAIN_NAME);

        self::assertNotSame($messageKey, $expectedMessage, 'The message key has no translation.');
        self::assertCount(1, $errors);
        self::assertInstanceOf(FormError::class, $errors[0]);
        self::assertSame($expectedMessage, $errors[0]->getMessage());
    }

    private function submit(string $logoSvg): FormInterface
    {
        return $this->submitFields([self::LOGO_FIELD => $logoSvg]);
    }

    /**
     * @param array<string, string> $fields
     */
    private function submitFields(array $fields): FormInterface
    {
        $form = $this->getService(TheliaFormFactory::class)
            ->createForm(
                SocialLoginConfigurationForm::getName(),
                FormType::class,
                [],
                ['csrf_protection' => false],
            )
            ->getForm();

        $form->submit($fields, false);

        return $form;
    }
}
