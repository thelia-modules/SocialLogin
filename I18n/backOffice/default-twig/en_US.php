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
    'Social login configuration' => 'Social login configuration',
    'Google' => 'Google',
    'Facebook' => 'Facebook',
    'Apple' => 'Apple',
    'Callback URL to declare in the %provider% console' => 'Callback URL to declare in the %provider% console',
    'Leave empty to keep the current value' => 'Leave empty to keep the current value',
    'Paste the SVG of the provider\'s official logo. For Apple, use the asset from Apple Design Resources. Leave empty to use the built-in brand mark. Presentation attributes only; scripts, styles, links and external references are removed.' => 'Paste the SVG of the provider\'s official logo. For Apple, use the asset from Apple Design Resources. Leave empty to use the built-in brand mark. Presentation attributes only; scripts, styles, links and external references are removed.',
    'Save' => 'Save',

    // Help texts: sign-in setup, one block per provider, plus the transverse HTTPS reminder
    'Sign-in requires HTTPS: a provider is only offered on the storefront once its fields below are filled in and the shop is served over TLS.' => 'Sign-in requires HTTPS: a provider is only offered on the storefront once its fields below are filled in and the shop is served over TLS.',
    'Create an OAuth client ID of type "Web application" in the Google Cloud Console (APIs & Services > Credentials), then add the callback URL below to its authorized redirect URIs. Thelia requests the openid, email and profile scopes automatically.' => 'Create an OAuth client ID of type "Web application" in the Google Cloud Console (APIs & Services > Credentials), then add the callback URL below to its authorized redirect URIs. Thelia requests the openid, email and profile scopes automatically.',
    'Create an app on Meta for Developers, add the "Facebook Login" product, then register the callback URL below as a valid OAuth redirect URI. The App ID and App secret are on the app dashboard.' => 'Create an app on Meta for Developers, add the "Facebook Login" product, then register the callback URL below as a valid OAuth redirect URI. The App ID and App secret are on the app dashboard.',
    'Sign in with Apple requires a paid Apple Developer account. Create a Services ID (not an App ID) and enable "Sign in with Apple" on it, enter it in the Service ID field below.' => 'Sign in with Apple requires a paid Apple Developer account. Create a Services ID (not an App ID) and enable "Sign in with Apple" on it, enter it in the Service ID field below.',
    'On the Services ID, declare your domain and the Return URL, the callback URL below, then verify the domain by hosting the file Apple provides at /.well-known/apple-developer-domain-association.txt (HTTPS, valid 7 days).' => 'On the Services ID, declare your domain and the Return URL, the callback URL below, then verify the domain by hosting the file Apple provides at /.well-known/apple-developer-domain-association.txt (HTTPS, valid 7 days).',
    'Create a Sign in with Apple private key (a .p8 file) and note its Key ID.' => 'Create a Sign in with Apple private key (a .p8 file) and note its Key ID.',
    'Find your Team ID and fill in the fields below with the Services ID, Team ID, Key ID and the content of the .p8 file.' => 'Find your Team ID and fill in the fields below with the Services ID, Team ID, Key ID and the content of the .p8 file.',
    'The client secret is generated automatically from the private key on every sign-in: there is nothing to renew manually.' => 'The client secret is generated automatically from the private key on every sign-in: there is nothing to renew manually.',
    "The customer's name is only provided on the very first authorization. The email address may be a private relay address (@privaterelay.appleid.com); it is accepted like any other." => "The customer's name is only provided on the very first authorization. The email address may be a private relay address (@privaterelay.appleid.com); it is accepted like any other.",
    'This is the Services ID identifier, not the App ID.' => 'This is the Services ID identifier, not the App ID.',
    'Found in the top-right corner of your Apple Developer account, under Membership.' => 'Found in the top-right corner of your Apple Developer account, under Membership.',
    'The Key ID shown when the Sign in with Apple private key was created.' => 'The Key ID shown when the Sign in with Apple private key was created.',
    'Paste the full content of the .p8 file downloaded when the key was created; it can only be downloaded once.' => 'Paste the full content of the .p8 file downloaded when the key was created; it can only be downloaded once.',
    'Linked account: %provider% (%email%), last login on %date%' => 'Linked account: %provider% (%email%), last login on %date%',
    'Linked account: %provider% (%email%)' => 'Linked account: %provider% (%email%)',
];
