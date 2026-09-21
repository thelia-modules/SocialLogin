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
    'Social login configuration' => 'Configuration de la connexion sociale',
    'Google' => 'Google',
    'Facebook' => 'Facebook',
    'Apple' => 'Apple',
    'Callback URL to declare in the %provider% console' => 'URL de callback à déclarer dans la console %provider%',
    'Leave empty to keep the current value' => 'Laisser vide pour conserver la valeur actuelle',
    'Paste the SVG of the provider\'s official logo. For Apple, use the asset from Apple Design Resources. Leave empty to use the built-in brand mark. Presentation attributes only; scripts, styles, links and external references are removed.' => 'Collez le SVG du logo officiel du fournisseur. Pour Apple, utilisez l’asset d’Apple Design Resources. Laissez vide pour utiliser la marque intégrée. Attributs de présentation uniquement ; les scripts, styles, liens et références externes sont supprimés.',
    'Save' => 'Enregistrer',

    // Textes d'aide : mise en place de la connexion, un bloc par fournisseur, plus le rappel transverse HTTPS
    'Sign-in requires HTTPS: a provider is only offered on the storefront once its fields below are filled in and the shop is served over TLS.' => 'La connexion nécessite le HTTPS : un fournisseur n’est proposé sur la boutique qu’une fois ses champs ci-dessous renseignés et le site servi en TLS.',
    'Create an OAuth client ID of type "Web application" in the Google Cloud Console (APIs & Services > Credentials), then add the callback URL below to its authorized redirect URIs. Thelia requests the openid, email and profile scopes automatically.' => 'Créez un identifiant client OAuth de type « Application Web » dans la Google Cloud Console (APIs et services > Identifiants), puis ajoutez l’URL de callback ci-dessous à ses URI de redirection autorisées. Thelia demande automatiquement les scopes openid, email et profil.',
    'Create an app on Meta for Developers, add the "Facebook Login" product, then register the callback URL below as a valid OAuth redirect URI. The App ID and App secret are on the app dashboard.' => 'Créez une application sur Meta for Developers, ajoutez le produit « Facebook Login », puis déclarez l’URL de callback ci-dessous comme URI de redirection OAuth valide. L’App ID et l’App Secret se trouvent sur le tableau de bord de l’application.',
    'Sign in with Apple requires a paid Apple Developer account. Create a Services ID (not an App ID) and enable "Sign in with Apple" on it, enter it in the Service ID field below.' => 'Sign in with Apple nécessite un compte Apple Developer payant. Créez un Services ID (pas un App ID) et activez « Sign in with Apple » dessus, saisissez-le dans le champ Service ID ci-dessous.',
    'On the Services ID, declare your domain and the Return URL, the callback URL below, then verify the domain by hosting the file Apple provides at /.well-known/apple-developer-domain-association.txt (HTTPS, valid 7 days).' => 'Sur le Services ID, déclarez votre domaine et la Return URL, l’URL de callback ci-dessous, puis vérifiez le domaine en hébergeant le fichier fourni par Apple à l’adresse /.well-known/apple-developer-domain-association.txt (HTTPS, valide 7 jours).',
    'Create a Sign in with Apple private key (a .p8 file) and note its Key ID.' => 'Créez une clé privée Sign in with Apple (un fichier .p8) et notez son Key ID.',
    'Find your Team ID and fill in the fields below with the Services ID, Team ID, Key ID and the content of the .p8 file.' => 'Trouvez votre Team ID et renseignez les champs ci-dessous avec le Services ID, le Team ID, le Key ID et le contenu du fichier .p8.',
    'The client secret is generated automatically from the private key on every sign-in: there is nothing to renew manually.' => 'Le client secret est généré automatiquement à partir de la clé privée à chaque connexion : il n’y a rien à renouveler manuellement.',
    "The customer's name is only provided on the very first authorization. The email address may be a private relay address (@privaterelay.appleid.com); it is accepted like any other." => 'Le nom du client n’est transmis qu’à la toute première autorisation. L’adresse e-mail peut être une adresse relais privée (@privaterelay.appleid.com) ; elle est acceptée comme n’importe quelle autre.',
    'This is the Services ID identifier, not the App ID.' => 'Il s’agit de l’identifiant Services ID, pas de l’App ID.',
    'Found in the top-right corner of your Apple Developer account, under Membership.' => 'Visible en haut à droite de votre compte Apple Developer, dans Membership.',
    'The Key ID shown when the Sign in with Apple private key was created.' => 'Le Key ID affiché lors de la création de la clé privée Sign in with Apple.',
    'Paste the full content of the .p8 file downloaded when the key was created; it can only be downloaded once.' => 'Collez le contenu intégral du fichier .p8 téléchargé lors de la création de la clé ; il ne peut être téléchargé qu’une seule fois.',
    'Linked account: %provider% (%email%), last login on %date%' => 'Compte lié : %provider% (%email%), dernière connexion le %date%',
    'Linked account: %provider% (%email%)' => 'Compte lié : %provider% (%email%)',
];
