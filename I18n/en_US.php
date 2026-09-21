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

return [
    // BO: configuration form, one key per provider generated from the registry
    'Enable %provider% login' => 'Enable %provider% login',
    'Client ID' => 'Client ID',
    'Client secret' => 'Client secret',
    'App ID' => 'App ID',
    'App secret' => 'App secret',
    'Service ID' => 'Service ID',
    'Team ID' => 'Team ID',
    'Key ID' => 'Key ID',
    'Private key (.p8 content)' => 'Private key (.p8 content)',
    '%provider% logo (SVG)' => '%provider% logo (SVG)',
    'Display order' => 'Display order',
    'Lower numbers appear first.' => 'Lower numbers appear first.',

    // Front: sign-in buttons, attachment page, account page
    'or' => 'or',
    'Continue with %provider%' => 'Continue with %provider%',
    'Link your account' => 'Link your account',
    'An account already exists for %email%. Enter its password to link this sign-in method to it.' => 'An account already exists for %email%. Enter its password to link this sign-in method to it.',
    'Link account' => 'Link account',
    'Social connections' => 'Social connections',
    'Social connection removed.' => 'Social connection removed.',
    'You have not linked any social account yet.' => 'You have not linked any social account yet.',
    'Last sign-in: %date%' => 'Last sign-in: %date%',
    'This is your only sign-in method. Set a password first.' => 'This is your only sign-in method. Set a password first.',
    'Disconnect' => 'Disconnect',

    // Front: notice shown on the login page for an outcome that is not an exception
    'sociallogin.notice.account_activation_required' => 'This address is linked to a pending guest order. Please check your mailbox for the activation code, or place your order again to receive a new one.',

    // Error keys thrown by SocialLoginException and its subclasses (SocialLogin::DOMAIN_NAME)
    'sociallogin.error.unknown_provider' => 'This sign-in provider does not exist.',
    'sociallogin.error.provider_not_configured' => 'This sign-in provider is not available at the moment.',
    'sociallogin.error.invalid_state' => 'Your sign-in attempt could not be verified. Please try again.',
    'sociallogin.error.provider_communication' => 'We could not reach the sign-in provider. Please try again.',
    'sociallogin.error.identity_token_verification' => 'The sign-in provider returned information we could not verify.',
    'sociallogin.error.email_not_provided' => 'The sign-in provider did not share an email address with us.',
    'sociallogin.error.email_not_verified' => 'The sign-in provider did not confirm this email address.',
    'sociallogin.error.pending_identity_not_found' => 'There is no pending sign-in to link. Please start again.',
    'sociallogin.error.invalid_password' => 'This password is incorrect.',
    'sociallogin.error.password_confirmation_required' => 'Please enter your password to remove your last sign-in method.',
    'sociallogin.error.identity_not_owned' => 'This social connection could not be found.',
    'sociallogin.error.too_many_attempts' => 'Too many attempts. Please try again later.',
    'sociallogin.error.unexpected' => 'Sorry, an unexpected error occurred. Please try again.',
    'sociallogin.error.identity_already_linked' => 'This social account is already linked to another customer account.',
    'sociallogin.error.identity_orphaned' => 'This social connection no longer refers to an existing account.',

    // BO: configuration form, logo field rejected at submission
    'sociallogin.error.invalid_logo_svg' => 'This logo could not be read as an SVG image, or nothing was left of it once scripts, styles and external references were removed. Paste the provider\'s official SVG file, or leave the field empty to use the built-in brand mark.',
    'sociallogin.error.logo_svg_too_large' => 'This logo is too large to be saved, once cleaned up for display. Paste a lighter SVG file, for instance one exported without metadata or embedded fonts.',

    // BO: configuration form, display order refused at submission
    'sociallogin.error.invalid_position' => 'Enter a whole number, 0 or more, or leave the field empty to keep the default order.',
];
