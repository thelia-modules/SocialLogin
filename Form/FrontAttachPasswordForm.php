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

use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Validator\Constraints\NotBlank;
use Thelia\Core\Translation\Translator;
use Thelia\Form\BaseForm;

/**
 * The password prompt of the attachment page: the one field {@see \SocialLogin\Service\IdentityAttachmentService::attach()}
 * needs to prove the visitor owns the account a provider's address already belongs to.
 */
final class FrontAttachPasswordForm extends BaseForm
{
    public static function getName(): string
    {
        return 'sociallogin_front_attach_password_form';
    }

    protected function buildForm(): void
    {
        $this->formBuilder->add('password', PasswordType::class, [
            'constraints' => [new NotBlank()],
            'label' => Translator::getInstance()->trans('Password'),
            'label_attr' => [
                'for' => 'password',
            ],
        ]);
    }
}
